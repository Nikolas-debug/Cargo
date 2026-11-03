<?php

namespace App\Http\Controllers\Api;

use App\Actions\Liquidaciones\AplicarPagoACuenta;
use App\Actions\Liquidaciones\RegistrarAnticipo;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnticipoRequest;
use App\Models\Anticipo;
use App\Models\Liquidacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Los pagos y anticipos que hace el consorcio, con su comprobante de ingreso.
 * La relación lista todos, estén o no aplicados a una cuenta de cobro.
 */
class PagoConsorcioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pagos = Anticipo::query()
            ->whereNotNull('consorcio_id')
            ->with(['consorcio:id,razon_social,nit', 'liquidacion:id,numero_cuenta,estado'])
            ->withExists('comprobante')
            ->when($request->integer('consorcio_id'), fn ($q, $id) => $q->where('consorcio_id', $id))
            ->when($request->input('desde'), fn ($q, $desde) => $q->whereDate('fecha', '>=', $desde))
            ->when($request->input('hasta'), fn ($q, $hasta) => $q->whereDate('fecha', '<=', $hasta))
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $pagos->map(fn (Anticipo $pago) => self::fila($pago)),
            'total' => $pagos->sum('valor'),
        ]);
    }

    public function store(AnticipoRequest $request, RegistrarAnticipo $registrar): JsonResponse
    {
        $cuenta = $request->input('liquidacion_id') ? Liquidacion::findOrFail($request->integer('liquidacion_id')) : null;

        if ($cuenta !== null && (! $cuenta->esCobro() || $cuenta->consorcio_id !== $request->integer('consorcio_id'))) {
            abort(422, 'La cuenta tiene que ser de cobro y del mismo consorcio del pago.');
        }

        $pago = $registrar($cuenta, $request->safe()->except(['archivo', 'liquidacion_id']), $request->user(), $request->file('archivo'));

        return response()->json(self::fila($pago->load(['consorcio', 'liquidacion'])->loadExists('comprobante')), 201);
    }

    /** Aplica el pago a una cuenta de cobro, o lo deja suelto con null. */
    public function aplicar(Request $request, Anticipo $anticipo, AplicarPagoACuenta $aplicar): JsonResponse
    {
        $datos = $request->validate(['liquidacion_id' => ['nullable', 'exists:liquidaciones,id']]);
        $cuenta = $datos['liquidacion_id'] ? Liquidacion::findOrFail($datos['liquidacion_id']) : null;

        $aplicar($anticipo, $cuenta);

        return response()->json(self::fila($anticipo->fresh(['consorcio', 'liquidacion'])->loadExists('comprobante')));
    }

    /** El archivo tal cual: la vista previa lo pide como blob con el token. */
    public function comprobante(Anticipo $anticipo): Response
    {
        $archivo = $anticipo->comprobante()->firstOrFail();

        return response(base64_decode($archivo->contenido), 200, [
            'Content-Type' => $archivo->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($archivo->nombre).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** @return array<string, mixed> */
    public static function fila(Anticipo $pago): array
    {
        return [
            'id' => $pago->id,
            'fecha' => $pago->fecha?->toDateString(),
            'valor' => $pago->valor,
            'numero_comprobante' => $pago->numero_comprobante,
            'observacion' => $pago->observacion,
            'consorcio_id' => $pago->consorcio_id,
            'consorcio' => $pago->consorcio?->razon_social,
            'nit' => $pago->consorcio?->nit,
            'cuenta' => $pago->liquidacion ? [
                'id' => $pago->liquidacion->id,
                'numero' => $pago->liquidacion->numero_cuenta,
                'estado' => $pago->liquidacion->estado,
            ] : null,
            'tiene_comprobante' => (bool) $pago->comprobante_exists,
        ];
    }
}
