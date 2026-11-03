<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un abono a una cuenta, o un pago del consorcio con su comprobante.
 * El consorcio solo se pide cuando el pago llega sin cuenta (ruta de pagos).
 */
class AnticipoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'valor' => ['required', 'integer', 'min:1'],
            'numero_comprobante' => ['nullable', 'string', 'max:40'],
            'observacion' => ['nullable', 'string', 'max:150'],
            // 2 MB es el tope de subida de PHP; las fotos se achican antes en el navegador.
            'archivo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:2048'],
            'consorcio_id' => [$this->routeIs('pagos.store') ? 'required' : 'prohibited', 'nullable', 'exists:consorcios,id'],
            'liquidacion_id' => [$this->routeIs('pagos.store') ? 'nullable' : 'prohibited', 'exists:liquidaciones,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.max' => 'El comprobante pesa más de 2 MB. Si es un PDF, escanéalo en menor calidad o súbelo como foto.',
            'archivo.mimes' => 'El comprobante tiene que ser una foto (JPG, PNG, WEBP) o un PDF.',
        ];
    }
}
