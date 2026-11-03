<?php

namespace App\Actions\Viajes;

use App\Actions\Tarifas\ResolverTarifa;
use App\Enums\EstadoViaje;
use App\Enums\TipoTarifa;
use App\Enums\TipoViaje;
use App\Exceptions\TarifaNoEncontrada;
use App\Models\CanteraMaterial;
use App\Models\User;
use App\Models\Viaje;
use App\Models\Volqueta;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registra un lote de viajes que comparten obra, fecha y ruta.
 *
 * Así es como trabaja el digitador: lo que se repite se escribe una vez y
 * cada fila aporta solo placa y m³. Los valores de las tarifas se resuelven
 * aquí y se CONGELAN en cada viaje.
 *
 * Todo el lote entra o no entra ninguno: si una placa no tiene tarifa de pago
 * pactada, es mejor fallar entero que dejar la mitad de los viajes guardados.
 */
class RegistrarViajes
{
    public function __construct(private ResolverTarifa $resolver) {}

    /**
     * @param  array{obra_id:int, tipo_viaje:string, fecha_viaje:string, cantera_material_id?:int|null, botadero_id?:int|null}  $cabecera
     * @param  array<int, array{volqueta_id:int, m3:int, cantidad?:int, conductor_id?:int|null, material_por_conductor?:bool, observaciones?:string|null}>  $filas
     * @return Collection<int, Viaje>
     */
    public function __invoke(array $cabecera, array $filas, User $usuario): Collection
    {
        $tipoViaje = TipoViaje::from($cabecera['tipo_viaje']);
        $fecha = $cabecera['fecha_viaje'];
        $obraId = (int) $cabecera['obra_id'];

        [$canteraId, $materialId, $valorMaterial] = $this->origen($tipoViaje, $cabecera['cantera_material_id'] ?? null);
        $botaderoId = $tipoViaje === TipoViaje::Retiro ? (int) $cabecera['botadero_id'] : null;

        // El cobro no depende de la volqueta: se resuelve una sola vez.
        $tarifaCobro = ($this->resolver)(
            tipo: TipoTarifa::Cobro,
            tipoViaje: $tipoViaje,
            obraId: $obraId,
            canteraId: $canteraId,
            botaderoId: $botaderoId,
            materialId: $materialId,
            terceroId: null,
            fecha: $fecha,
        );

        return DB::transaction(function () use ($cabecera, $filas, $usuario, $tipoViaje, $fecha, $obraId, $canteraId, $materialId, $botaderoId, $tarifaCobro, $valorMaterial) {
            $viajes = new Collection();

            foreach ($filas as $fila) {
                $volqueta = Volqueta::with('propietario')->findOrFail($fila['volqueta_id']);
                $conductorId = $fila['conductor_id'] ?? $volqueta->conductor_habitual_id;

                $atributos = [
                    'volqueta_id' => $volqueta->id,
                    'conductor_id' => $conductorId,
                    'tipo_viaje' => $tipoViaje,
                    'obra_id' => $obraId,
                    'cantera_material_id' => $cabecera['cantera_material_id'] ?? null,
                    'botadero_id' => $botaderoId,
                    'fecha_viaje' => $fecha,
                    'm3_registrado' => (int) $fila['m3'],
                    'estado' => EstadoViaje::PorConfirmar,
                    'valor_cobro_unitario' => $tarifaCobro->valor,
                    'unidad_cobro' => $tarifaCobro->unidad,
                    'valor_material_m3' => $valorMaterial,
                    // El conductor consiguió el material: se le debe a él, no a la cantera.
                    'material_por_conductor' => $tipoViaje === TipoViaje::Suministro && ! empty($fila['material_por_conductor']),
                    'registrado_por' => $usuario->id,
                    'observaciones' => $fila['observaciones'] ?? null,
                ];

                $atributos += $this->pago($volqueta, $conductorId, $tipoViaje, $obraId, $canteraId, $botaderoId, $materialId, $fecha);

                // "La UB500 hizo 5 viajes": una fila, cinco viajes de esos m³ cada uno.
                for ($i = 0; $i < (int) ($fila['cantidad'] ?? 1); $i++) {
                    $viajes->push(Viaje::create($atributos));
                }
            }

            return $viajes;
        });
    }

    /**
     * Volqueta de tercero -> se le paga al dueño, por viaje.
     * Volqueta propia    -> se le paga al conductor, un porcentaje de la base de la ruta.
     *
     * @return array<string, mixed>
     */
    private function pago(
        Volqueta $volqueta,
        ?int $conductorId,
        TipoViaje $tipoViaje,
        int $obraId,
        ?int $canteraId,
        ?int $botaderoId,
        ?int $materialId,
        string $fecha,
    ): array {
        if (! $volqueta->es_propia) {
            $tarifa = ($this->resolver)(
                tipo: TipoTarifa::PagoPropietario,
                tipoViaje: $tipoViaje,
                obraId: $obraId,
                canteraId: $canteraId,
                botaderoId: $botaderoId,
                materialId: $materialId,
                terceroId: $volqueta->propietario_id,
                fecha: $fecha,
            );

            return [
                'valor_pago_unitario' => $tarifa->valor,
                'unidad_pago' => $tarifa->unidad,
            ];
        }

        $tarifa = ($this->resolver)(
            tipo: TipoTarifa::PagoConductor,
            tipoViaje: $tipoViaje,
            obraId: $obraId,
            canteraId: $canteraId,
            botaderoId: $botaderoId,
            materialId: $materialId,
            terceroId: $conductorId,
            fecha: $fecha,
        );

        // Se guardan los tres componentes, no solo el resultado: así el
        // conductor puede ver de dónde salió su pago, y un cambio futuro de
        // base o de porcentaje no reescribe lo ya liquidado.
        return [
            'valor_base_conductor' => $tarifa->valor_base,
            'porcentaje_conductor' => $tarifa->porcentaje,
            'valor_pago_conductor' => $tarifa->pagoAlConductor(),
        ];
    }

    /**
     * La cantera, el material y su precio por m³. El precio va encima del
     * flete en el cobro al consorcio, así que sin precio no se registra: un
     * material en cero se cobra en cero y nadie lo nota hasta la liquidación.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    private function origen(TipoViaje $tipoViaje, ?int $canteraMaterialId): array
    {
        if ($tipoViaje === TipoViaje::Retiro || $canteraMaterialId === null) {
            return [null, null, null];
        }

        $canteraMaterial = CanteraMaterial::with('cantera', 'material')->findOrFail($canteraMaterialId);

        if ($canteraMaterial->precio_compra_m3 === null) {
            throw new TarifaNoEncontrada(sprintf(
                '%s en la cantera %s no tiene precio por m³. Pónselo en Canteras antes de registrar viajes con ese material.',
                ucfirst($canteraMaterial->material->nombre),
                $canteraMaterial->cantera->nombre,
            ));
        }

        return [$canteraMaterial->cantera_id, $canteraMaterial->material_id, $canteraMaterial->precio_compra_m3];
    }
}
