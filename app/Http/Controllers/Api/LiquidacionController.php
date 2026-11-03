<?php

namespace App\Http\Controllers\Api;

use App\Actions\Liquidaciones\CerrarLiquidacion;
use App\Actions\Liquidaciones\GenerarLiquidacion;
use App\Actions\Liquidaciones\RegistrarAnticipo;
use App\Enums\TipoLiquidacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnticipoRequest;
use App\Http\Requests\GenerarLiquidacionRequest;
use App\Http\Resources\LiquidacionResource;
use App\Models\Anticipo;
use App\Models\Liquidacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LiquidacionController extends Controller
{
    /** Los anticipos traen solo si tienen comprobante, no el archivo. */
    private static function relaciones(): array
    {
        return ['consorcio', 'tercero', 'cantera', 'anticipos' => fn ($q) => $q->withExists('comprobante')->orderBy('fecha')];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $liquidaciones = Liquidacion::with(self::relaciones())
            ->when($request->input('tipo'), fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($request->input('estado'), fn ($q, $estado) => $q->where('estado', $estado))
            ->orderByDesc('fecha_fin')
            ->paginate($request->integer('por_pagina', 25));

        return LiquidacionResource::collection($liquidaciones);
    }

    public function store(GenerarLiquidacionRequest $request, GenerarLiquidacion $generar): JsonResponse
    {
        $liquidacion = $generar(
            TipoLiquidacion::from($request->input('tipo')),
            $request->input('consorcio_id'),
            $request->input('tercero_id'),
            $request->input('desde'),
            $request->input('hasta'),
            $request->input('cantera_id'),
            $request->input('numero_cuenta') ? $request->integer('numero_cuenta') : null,
        );

        return response()->json(
            new LiquidacionResource($liquidacion->load(self::relaciones())),
            201
        );
    }

    public function show(Liquidacion $liquidacion): LiquidacionResource
    {
        return new LiquidacionResource(
            $liquidacion->load(self::relaciones())->cargarViajes([
                'volqueta', 'obra', 'canteraMaterial.cantera', 'canteraMaterial.material', 'botadero', 'vale',
            ])
        );
    }

    /**
     * Corregir el número mientras la cuenta está abierta: las relaciones
     * vienen numeradas desde el Excel y la del sistema tiene que seguirlas.
     */
    public function renumerar(Request $request, Liquidacion $liquidacion): LiquidacionResource
    {
        abort_unless($liquidacion->estaAbierta(), 409, 'La cuenta está cerrada: su número ya no cambia.');

        $datos = $request->validate([
            'numero_cuenta' => [
                'required', 'integer', 'min:1', 'max:99999',
                Liquidacion::numeroLibre($liquidacion->tipo->value, $liquidacion->consorcio_id, $liquidacion->tercero_id, $liquidacion->cantera_id, $liquidacion->id),
            ],
        ], ['numero_cuenta.unique' => 'Ese número ya lo tiene otra cuenta del mismo destinatario.']);

        $liquidacion->update($datos);

        return new LiquidacionResource($liquidacion->load(self::relaciones()));
    }

    public function cerrar(Request $request, Liquidacion $liquidacion, CerrarLiquidacion $cerrar): LiquidacionResource
    {
        $this->authorize('cerrar', $liquidacion);

        return new LiquidacionResource(
            $cerrar($liquidacion, $request->user())->load(self::relaciones())
        );
    }

    public function agregarAnticipo(AnticipoRequest $request, Liquidacion $liquidacion, RegistrarAnticipo $registrar): JsonResponse
    {
        $registrar($liquidacion, $request->safe()->except('archivo'), $request->user(), $request->file('archivo'));

        return response()->json(
            new LiquidacionResource($liquidacion->fresh()->load(self::relaciones())),
            201
        );
    }

    public function eliminarAnticipo(Anticipo $anticipo): JsonResponse
    {
        $liquidacion = $anticipo->liquidacion;

        abort_if($liquidacion !== null && ! $liquidacion->estaAbierta(), 409, 'No se pueden quitar anticipos de una cuenta cerrada.');

        $anticipo->delete();

        if ($liquidacion === null) {
            return response()->json(['message' => 'Pago eliminado.']);
        }

        $liquidacion->load('anticipos')->cargarViajes()->recalcularTotales()->save();

        return response()->json(new LiquidacionResource($liquidacion->load(self::relaciones())));
    }
}
