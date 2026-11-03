<?php

namespace App\Http\Requests;

use App\Enums\TipoLiquidacion;
use App\Models\Liquidacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerarLiquidacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esCobro = $this->input('tipo') === TipoLiquidacion::Cobro->value;
        $esPago = $this->input('tipo') === TipoLiquidacion::Pago->value;
        $esMaterial = $this->input('tipo') === TipoLiquidacion::Material->value;

        return [
            'tipo' => ['required', Rule::enum(TipoLiquidacion::class)],
            'consorcio_id' => [Rule::requiredIf($esCobro), Rule::prohibitedIf(! $esCobro), 'nullable', 'exists:consorcios,id'],
            'tercero_id' => [Rule::requiredIf($esPago), Rule::prohibitedIf(! $esPago), 'nullable', 'exists:terceros,id'],
            'cantera_id' => [Rule::requiredIf($esMaterial), Rule::prohibitedIf(! $esMaterial), 'nullable', 'exists:canteras,id'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            // Vacío = el siguiente. Lo escribe quien trae la numeración del Excel.
            'numero_cuenta' => [
                'nullable', 'integer', 'min:1', 'max:99999',
                Liquidacion::numeroLibre($this->input('tipo'), $this->input('consorcio_id'), $this->input('tercero_id'), $this->input('cantera_id')),
            ],
        ];
    }
}
