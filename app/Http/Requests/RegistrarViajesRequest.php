<?php

namespace App\Http\Requests;

use App\Enums\TipoViaje;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El lote: una cabecera con lo que se repite y las filas con placa y m³.
 */
class RegistrarViajesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esRetiro = $this->input('tipo_viaje') === TipoViaje::Retiro->value;

        return [
            'obra_id' => ['required', 'exists:obras,id'],
            'tipo_viaje' => ['required', Rule::enum(TipoViaje::class)],
            'fecha_viaje' => ['required', 'date', 'before_or_equal:today'],

            // Un suministro sale de una cantera; un retiro va a un botadero.
            // Nunca los dos, nunca ninguno.
            'cantera_material_id' => [
                Rule::requiredIf(! $esRetiro),
                Rule::prohibitedIf($esRetiro),
                'nullable',
                'exists:cantera_material,id',
            ],
            'botadero_id' => [
                Rule::requiredIf($esRetiro),
                Rule::prohibitedIf(! $esRetiro),
                'nullable',
                'exists:botaderos,id',
            ],

            'viajes' => ['required', 'array', 'min:1', 'max:200'],
            'viajes.*.volqueta_id' => ['required', 'exists:volquetas,id'],
            'viajes.*.m3' => ['required', 'integer', 'min:1', 'max:60'],
            // m³ de CADA viaje: la fila se multiplica en esa cantidad de viajes.
            'viajes.*.cantidad' => ['nullable', 'integer', 'min:1', 'max:30'],
            'viajes.*.conductor_id' => ['nullable', 'exists:terceros,id'],
            'viajes.*.material_por_conductor' => ['nullable', 'boolean'],
            'viajes.*.observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'cantera_material_id.required' => 'Un viaje de suministro necesita cantera y material.',
            'botadero_id.required' => 'Un viaje de retiro necesita el botadero a donde se lleva el escombro.',
            'botadero_id.prohibited' => 'Un viaje de suministro no lleva botadero.',
            'cantera_material_id.prohibited' => 'Un viaje de retiro no lleva cantera ni material.',
            'fecha_viaje.before_or_equal' => 'No se pueden registrar viajes con fecha futura.',
        ];
    }

    /** @return array{obra_id:int, tipo_viaje:string, fecha_viaje:string, cantera_material_id:int|null, botadero_id:int|null} */
    public function cabecera(): array
    {
        return [
            'obra_id' => $this->integer('obra_id'),
            'tipo_viaje' => $this->string('tipo_viaje')->value(),
            'fecha_viaje' => $this->string('fecha_viaje')->value(),
            'cantera_material_id' => $this->input('cantera_material_id'),
            'botadero_id' => $this->input('botadero_id'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function filas(): array
    {
        return $this->input('viajes');
    }
}
