<?php

namespace App\Http\Controllers\Api;

use App\Actions\Viajes\AnularViaje;
use App\Actions\Viajes\RegistrarViajes;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarViajesRequest;
use App\Http\Resources\ViajeResource;
use App\Models\Viaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ViajeController extends Controller
{
    private const RELACIONES = ['volqueta.propietario', 'conductor', 'obra', 'canteraMaterial.cantera', 'canteraMaterial.material', 'botadero', 'vale'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $viajes = Viaje::with(self::RELACIONES)
            ->when($request->integer('obra_id'), fn ($q, $id) => $q->deLaObra($id))
            ->when($request->input('estado'), fn ($q, $estado) => $q->where('estado', $estado))
            ->when($request->input('placa'), fn ($q, $placa) => $q->whereHas('volqueta', fn ($v) => $v->where('placa', 'ilike', "%{$placa}%")))
            ->when($request->integer('vale'), fn ($q, $numero) => $q->whereHas('vale', fn ($v) => $v->where('numero', $numero)))
            ->when(
                $request->input('desde') && $request->input('hasta'),
                fn ($q) => $q->entreFechas($request->input('desde'), $request->input('hasta'))
            )
            ->orderByDesc('fecha_viaje')
            ->orderByDesc('id')
            ->paginate($request->integer('por_pagina', 50));

        return ViajeResource::collection($viajes);
    }

    /** Registro en lote: una cabecera y muchas filas de placa + m³. */
    public function store(RegistrarViajesRequest $request, RegistrarViajes $registrar): JsonResponse
    {
        $viajes = $registrar($request->cabecera(), $request->filas(), $request->user());

        return response()->json([
            'message' => "Se registraron {$viajes->count()} viajes por confirmar.",
            'viajes' => ViajeResource::collection($viajes->load(self::RELACIONES)),
        ], 201);
    }

    public function show(Viaje $viaje): ViajeResource
    {
        return new ViajeResource($viaje->load(self::RELACIONES));
    }

    /**
     * Corregir un viaje que todavía no entró en una cuenta. El bloqueo real
     * lo aplica el modelo, no este método.
     */
    public function update(Request $request, Viaje $viaje): ViajeResource
    {
        $datos = $request->validate([
            'volqueta_id' => ['sometimes', 'exists:volquetas,id'],
            'conductor_id' => ['nullable', 'exists:terceros,id'],
            'fecha_viaje' => ['sometimes', 'date', 'before_or_equal:today'],
            'm3_registrado' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'material_por_conductor' => [$viaje->esSuministro() ? 'sometimes' : 'prohibited', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        $viaje->update($datos);

        return new ViajeResource($viaje->load(self::RELACIONES));
    }

    public function anular(Request $request, Viaje $viaje, AnularViaje $anular): ViajeResource
    {
        $this->authorize('anular', $viaje);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:200']]);

        return new ViajeResource($anular($viaje, $datos['motivo'] ?? null)->load(self::RELACIONES));
    }
}
