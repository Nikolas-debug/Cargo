<?php

namespace App\Actions\Liquidaciones;

use App\Enums\EstadoLiquidacion;
use App\Enums\TipoLiquidacion;
use App\Models\Liquidacion;
use App\Models\Viaje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Arma una cuenta con los viajes CONFIRMADOS de un periodo que todavía no
 * estén en otra cuenta. Los viajes por confirmar no entran: solo se paga y se
 * cobra lo que tiene vale.
 *
 * La cuenta nace abierta; se pueden agregar anticipos hasta cerrarla.
 *
 * El número lo puede poner quien la genera: las cuentas de antes del sistema
 * se llevaban en Excel, y la que sigue no es la 1 sino la 14.
 */
class GenerarLiquidacion
{
    public function __invoke(
        TipoLiquidacion $tipo,
        ?int $consorcioId,
        ?int $terceroId,
        string $desde,
        string $hasta,
        ?int $canteraId = null,
        ?int $numeroCuenta = null,
    ): Liquidacion {
        return DB::transaction(function () use ($tipo, $consorcioId, $terceroId, $canteraId, $desde, $hasta, $numeroCuenta) {
            $viajes = $this->viajesElegibles($tipo, $consorcioId, $terceroId, $canteraId, $desde, $hasta)->get();

            if ($viajes->isEmpty()) {
                throw ValidationException::withMessages([
                    'periodo' => 'No hay viajes confirmados sin liquidar en ese periodo.',
                ]);
            }

            $liquidacion = Liquidacion::create([
                'tipo' => $tipo,
                'numero_cuenta' => $numeroCuenta ?? Liquidacion::siguienteNumero($tipo, $consorcioId, $terceroId, $canteraId),
                'consorcio_id' => $consorcioId,
                'tercero_id' => $terceroId,
                'cantera_id' => $canteraId,
                'fecha_inicio' => $desde,
                'fecha_fin' => $hasta,
                'estado' => EstadoLiquidacion::Abierta,
            ]);

            Viaje::whereIn('id', $viajes->pluck('id'))->update([Liquidacion::columnaEnViajes($tipo) => $liquidacion->id]);

            $liquidacion->load('anticipos')->cargarViajes()->recalcularTotales()->save();

            return $liquidacion;
        });
    }

    private function viajesElegibles(
        TipoLiquidacion $tipo,
        ?int $consorcioId,
        ?int $terceroId,
        ?int $canteraId,
        string $desde,
        string $hasta,
    ): Builder {
        $query = Viaje::query()
            ->confirmados()
            ->entreFechas($desde, $hasta);

        if ($tipo === TipoLiquidacion::Cobro) {
            return $query
                ->sinLiquidarCobro()
                ->whereHas('obra', fn (Builder $q) => $q->where('consorcio_id', $consorcioId));
        }

        if ($tipo === TipoLiquidacion::Material) {
            return $query
                ->sinLiquidarMaterial()
                ->materialDeCantera()
                ->whereHas('canteraMaterial', fn (Builder $q) => $q->where('cantera_id', $canteraId));
        }

        // Una cuenta de pago es de un dueño de volquetas o de un conductor propio.
        return $query
            ->sinLiquidarPago()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $sub) => $sub->delPropietario($terceroId))
                ->orWhere(fn (Builder $sub) => $sub->delConductorPropio($terceroId))
            );
    }
}
