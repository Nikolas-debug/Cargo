<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Los ocho catálogos son el mismo CRUD con distinto modelo y distintas reglas.
 * Escribirlo ocho veces solo multiplica los sitios donde corregir un bug.
 *
 * Cada catálogo dice con qué columna marca lo que está en uso: unos tienen
 * `activo` booleano, las volquetas tienen `estado`, los consorcios
 * `estado_contrato`.
 */
abstract class CatalogoController extends Controller
{
    /** @var class-string<Model> */
    protected string $modelo;

    /** @var array<int, string> */
    protected array $relaciones = [];

    /** @var array<int, string> */
    protected array $buscables = ['nombre'];

    protected string $columnaEstado = 'activo';

    protected mixed $valorActivo = true;

    protected mixed $valorInactivo = false;

    /** @return array<string, mixed> */
    abstract protected function reglas(Request $request, ?Model $registro = null): array;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->modelo::query()->with($this->relaciones);

        if ($busqueda = $request->string('q')->trim()->value()) {
            $query->where(function (Builder $q) use ($busqueda) {
                foreach ($this->buscables as $columna) {
                    $q->orWhere($columna, 'ilike', "%{$busqueda}%");
                }
            });
        }

        if ($request->boolean('solo_activos')) {
            $query->where($this->columnaEstado, $this->valorActivo);
        }

        return JsonResource::collection(
            $query->orderBy($this->buscables[0])->paginate($request->integer('por_pagina', 25))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $registro = $this->modelo::create($request->validate($this->reglas($request)));

        return response()->json(new JsonResource($registro->load($this->relaciones)), 201);
    }

    public function show(int $id): JsonResource
    {
        return new JsonResource($this->modelo::with($this->relaciones)->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResource
    {
        $registro = $this->modelo::findOrFail($id);
        $registro->update($request->validate($this->reglas($request, $registro)));

        return new JsonResource($registro->load($this->relaciones));
    }

    /**
     * Los catálogos no se borran: se desactivan. Un tercero borrado dejaría
     * viajes históricos apuntando al vacío.
     */
    public function destroy(int $id): JsonResponse
    {
        $registro = $this->modelo::findOrFail($id);
        $registro->update([$this->columnaEstado => $this->valorInactivo]);

        return response()->json(['message' => 'Registro desactivado.']);
    }

    /** Deshace un "Desactivar" dado por error. */
    public function reactivar(int $id): JsonResource
    {
        $registro = $this->modelo::findOrFail($id);
        $registro->update([$this->columnaEstado => $this->valorActivo]);

        return new JsonResource($registro->load($this->relaciones));
    }
}
