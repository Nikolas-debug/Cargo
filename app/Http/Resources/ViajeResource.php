<?php

namespace App\Http\Resources;

use App\Models\Viaje;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Viaje */
class ViajeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha_viaje' => $this->fecha_viaje?->toDateString(),
            'tipo_viaje' => $this->tipo_viaje,
            'estado' => $this->estado,

            'placa' => $this->whenLoaded('volqueta', fn () => $this->volqueta->placa),
            'conductor' => $this->whenLoaded('conductor', fn () => $this->conductor?->nombre_completo),

            'ruta' => $this->ruta(),
            'material' => $this->whenLoaded('canteraMaterial', fn () => $this->canteraMaterial?->material?->nombre),
            'cantera' => $this->whenLoaded('canteraMaterial', fn () => $this->canteraMaterial?->cantera?->nombre),
            'botadero' => $this->whenLoaded('botadero', fn () => $this->botadero?->nombre),

            'm3_registrado' => $this->m3_registrado,
            'm3_confirmado' => $this->m3_confirmado,
            // La diferencia entre lo acordado y lo que dijo el vale es la
            // señal de seguimiento del administrador.
            'ajustado' => $this->m3_confirmado !== null && $this->m3_confirmado !== $this->m3_registrado,

            'valor_cobro_unitario' => $this->valor_cobro_unitario,
            'unidad_cobro' => $this->unidad_cobro,
            'valor_material_m3' => $this->valor_material_m3,
            'total_flete' => $this->totalFlete(),
            'total_material' => $this->totalMaterial(),
            'total_cobro' => $this->totalCobro(),
            'material_por_conductor' => (bool) $this->material_por_conductor,
            'total_reembolso_material' => $this->totalReembolsoMaterial(),

            'valor_pago_unitario' => $this->valor_pago_unitario,
            'unidad_pago' => $this->unidad_pago,
            'total_pago' => $this->totalPagoPropietario() + $this->totalPagoConductor() + $this->totalReembolsoMaterial(),

            'pago_conductor' => $this->when($this->valor_pago_conductor !== null, fn () => [
                'base' => $this->valor_base_conductor,
                'porcentaje' => $this->porcentaje_conductor,
                'valor' => $this->valor_pago_conductor,
            ]),

            'vale' => $this->whenLoaded('vale', fn () => $this->vale?->numero),
            'fecha_confirmacion' => $this->fecha_confirmacion?->toIso8601String(),
            'liquidado' => $this->estaLiquidado(),
            'observaciones' => $this->observaciones,
        ];
    }

    private function ruta(): ?string
    {
        // Sin las relaciones cargadas no se arma la ruta: con lazy loading
        // apagado, leerlas aquí tumba la respuesta entera.
        $origen = $this->esSuministro() ? 'canteraMaterial' : 'botadero';

        if (! $this->relationLoaded('obra') || ! $this->relationLoaded($origen)) {
            return null;
        }

        if ($this->esSuministro()) {
            $cantera = $this->canteraMaterial?->cantera?->nombre ?? '?';

            return "{$cantera} → {$this->obra->nombre}";
        }

        $botadero = $this->botadero?->nombre ?? '?';

        return "{$this->obra->nombre} → {$botadero}";
    }
}
