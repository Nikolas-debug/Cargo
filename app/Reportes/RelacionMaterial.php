<?php

namespace App\Reportes;

use App\Enums\EstadoViaje;
use App\Enums\TipoLiquidacion;
use App\Models\Anticipo;
use App\Models\Cantera;
use App\Models\Viaje;

/**
 * La hoja "VIAJES RELLENO RELACION" del Excel de volquetas operando: el
 * crédito con una cantera, día por día.
 *
 *   filas    -> un día y un material (con su precio): si cambia el precio, otra fila
 *   columnas -> las obras a donde se llevó
 *   celda    -> cuántos viajes; en verde si todos tienen vale y cuadran, en
 *               rojo si hay viajes sin vale o el vale dijo otros m³
 *
 * Lo que se le debe a la cantera es solo lo confirmado con vale y que no puso
 * el conductor. Los abonos son los de las cuentas de material de la cantera.
 */
class RelacionMaterial
{
    /** @return array<string, mixed> */
    public function __invoke(Cantera $cantera, string $desde, string $hasta): array
    {
        $viajes = Viaje::query()
            ->with(['obra:id,nombre', 'canteraMaterial.material:id,nombre'])
            ->whereHas('canteraMaterial', fn ($q) => $q->where('cantera_id', $cantera->id))
            ->where('estado', '!=', EstadoViaje::Anulado)
            ->entreFechas($desde, $hasta)
            ->orderBy('fecha_viaje')
            ->get();

        $obras = $viajes->pluck('obra')->unique('id')->sortBy('nombre')->values();
        $filas = [];

        foreach ($viajes as $viaje) {
            $clave = $viaje->fecha_viaje->toDateString().'|'.$viaje->canteraMaterial->material_id.'|'.$viaje->valor_material_m3;

            $fila = $filas[$clave] ??= [
                'fecha' => $viaje->fecha_viaje->toDateString(),
                'material' => $viaje->canteraMaterial->material->nombre,
                'precio_m3' => $viaje->valor_material_m3,
                'celdas' => [],
                'viajes' => 0,
                'compra' => 0,
                'por_conductor' => 0,
            ];

            $celda = $fila['celdas'][$viaje->obra_id] ?? ['viajes' => 0, 'sin_vale' => 0, 'ajustados' => 0];
            $celda['viajes']++;

            if ($viaje->estado === EstadoViaje::PorConfirmar) {
                $celda['sin_vale']++;
            } elseif ($viaje->m3_confirmado !== $viaje->m3_registrado) {
                $celda['ajustados']++;
            }

            $fila['celdas'][$viaje->obra_id] = $celda;
            $fila['viajes']++;

            if ($viaje->material_por_conductor) {
                $fila['por_conductor']++;
            } elseif ($viaje->estaConfirmado()) {
                $fila['compra'] += $viaje->totalMaterial();
            }

            $filas[$clave] = $fila;
        }

        $pagos = Anticipo::query()
            ->whereHas('liquidacion', fn ($q) => $q->where('tipo', TipoLiquidacion::Material)->where('cantera_id', $cantera->id))
            ->with('liquidacion:id,numero_cuenta')
            ->withExists('comprobante')
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderBy('fecha')
            ->get();

        $compra = array_sum(array_column($filas, 'compra'));
        $abonado = (int) $pagos->sum('valor');

        return [
            'cantera' => $cantera->nombre,
            'desde' => $desde,
            'hasta' => $hasta,
            'obras' => $obras->map(fn ($obra) => ['id' => $obra->id, 'nombre' => $obra->nombre]),
            'filas' => array_values(array_map(function (array $fila) {
                foreach ($fila['celdas'] as &$celda) {
                    $celda['cuadra'] = $celda['sin_vale'] === 0 && $celda['ajustados'] === 0;
                }

                return $fila;
            }, $filas)),
            'pagos' => $pagos->map(fn (Anticipo $pago) => [
                'id' => $pago->id,
                'fecha' => $pago->fecha->toDateString(),
                'valor' => $pago->valor,
                'numero_comprobante' => $pago->numero_comprobante,
                'cuenta' => $pago->liquidacion?->numero_cuenta,
                'tiene_comprobante' => (bool) $pago->comprobante_exists,
            ]),
            'totales' => [
                'viajes' => $viajes->count(),
                'sin_vale' => $viajes->where('estado', EstadoViaje::PorConfirmar)->count(),
                'compra' => $compra,
                'abonado' => $abonado,
                'saldo' => $compra - $abonado,
            ],
        ];
    }
}
