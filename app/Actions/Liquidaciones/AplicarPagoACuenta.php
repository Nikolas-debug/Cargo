<?php

namespace App\Actions\Liquidaciones;

use App\Exceptions\LiquidacionCerrada;
use App\Models\Anticipo;
use App\Models\Liquidacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El anticipo del consorcio llega al iniciar la obra, antes que la cuenta.
 * Cuando la cuenta de cobro se genera, el pago se le aplica y le baja el
 * saldo. También se puede quitar (null), mientras ninguna esté cerrada.
 */
class AplicarPagoACuenta
{
    public function __invoke(Anticipo $pago, ?Liquidacion $cuenta): Anticipo
    {
        if ($pago->consorcio_id === null) {
            throw ValidationException::withMessages(['liquidacion_id' => 'Solo los pagos de un consorcio se mueven entre cuentas.']);
        }

        if ($cuenta !== null && (! $cuenta->esCobro() || $cuenta->consorcio_id !== $pago->consorcio_id)) {
            throw ValidationException::withMessages(['liquidacion_id' => 'La cuenta tiene que ser de cobro y del mismo consorcio del pago.']);
        }

        $anterior = $pago->liquidacion;

        foreach ([$anterior, $cuenta] as $afectada) {
            if ($afectada !== null && ! $afectada->estaAbierta()) {
                throw new LiquidacionCerrada("La cuenta #{$afectada->numero_cuenta} está cerrada: su saldo ya no cambia.");
            }
        }

        return DB::transaction(function () use ($pago, $cuenta, $anterior) {
            $pago->update(['liquidacion_id' => $cuenta?->id]);

            foreach (array_filter([$anterior, $cuenta]) as $afectada) {
                $afectada->load('anticipos')->cargarViajes()->recalcularTotales()->save();
            }

            return $pago;
        });
    }
}
