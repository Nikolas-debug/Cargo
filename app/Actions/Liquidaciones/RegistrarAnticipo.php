<?php

namespace App\Actions\Liquidaciones;

use App\Exceptions\LiquidacionCerrada;
use App\Models\Anticipo;
use App\Models\Liquidacion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un abono. Baja el saldo, como el pie del Excel:
 * "ANTICIPO 13 DE AGOSTO: 8.000.000 -> SALDO PENDIENTE: 15.400.000".
 *
 * Va contra una cuenta (al consorcio, al dueño o a la cantera) o, si es el
 * anticipo del consorcio al iniciar la obra, solo contra el consorcio: la
 * cuenta todavía no existe y se le aplica después.
 */
class RegistrarAnticipo
{
    /**
     * @param  array{fecha:string, valor:int, numero_comprobante?:string|null, observacion?:string|null, consorcio_id?:int|null}  $datos
     */
    public function __invoke(?Liquidacion $liquidacion, array $datos, User $usuario, ?UploadedFile $archivo = null): Anticipo
    {
        if ($liquidacion !== null && ! $liquidacion->estaAbierta()) {
            throw new LiquidacionCerrada('No se pueden agregar anticipos a una cuenta cerrada.');
        }

        $consorcioId = $liquidacion?->esCobro() ? $liquidacion->consorcio_id : ($datos['consorcio_id'] ?? null);

        if ($liquidacion === null && $consorcioId === null) {
            throw ValidationException::withMessages(['consorcio_id' => 'El pago tiene que ser de un consorcio o de una cuenta.']);
        }

        return DB::transaction(function () use ($liquidacion, $datos, $usuario, $archivo, $consorcioId) {
            $anticipo = Anticipo::create([
                'liquidacion_id' => $liquidacion?->id,
                'consorcio_id' => $consorcioId,
                'fecha' => $datos['fecha'],
                'valor' => $datos['valor'],
                'numero_comprobante' => $datos['numero_comprobante'] ?? null,
                'observacion' => $datos['observacion'] ?? null,
                'registrado_por' => $usuario->id,
            ]);

            if ($archivo !== null) {
                $anticipo->comprobante()->create([
                    'nombre' => $archivo->getClientOriginalName(),
                    'mime' => $archivo->getMimeType(),
                    'tamano' => $archivo->getSize(),
                    'contenido' => base64_encode($archivo->get()),
                ]);
            }

            $liquidacion?->load('anticipos')->cargarViajes()->recalcularTotales()->save();

            return $anticipo;
        });
    }
}
