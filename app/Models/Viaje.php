<?php

namespace App\Models;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Enums\UnidadTarifa;
use App\Exceptions\ViajeBloqueado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El corazón del sistema.
 *
 * Los valores unitarios NO se leen de tarifas al consultar: se copian aquí al
 * registrar y se quedan congelados. Un cambio de tarifa posterior no puede
 * reescribir el total de una cuenta ya cobrada.
 */
class Viaje extends Model
{
    use HasFactory;

    protected $fillable = [
        'volqueta_id', 'conductor_id', 'tipo_viaje', 'obra_id',
        'cantera_material_id', 'botadero_id', 'fecha_viaje',
        'm3_registrado', 'm3_confirmado',
        'valor_cobro_unitario', 'unidad_cobro', 'valor_material_m3', 'material_por_conductor',
        'valor_pago_unitario', 'unidad_pago',
        'valor_base_conductor', 'porcentaje_conductor', 'valor_pago_conductor',
        'estado', 'vale_id', 'fecha_confirmacion',
        'liquidacion_cobro_id', 'liquidacion_pago_id', 'liquidacion_material_id',
        'registrado_por', 'confirmado_por', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'tipo_viaje' => TipoViaje::class,
            'estado' => EstadoViaje::class,
            'unidad_cobro' => UnidadTarifa::class,
            'unidad_pago' => UnidadTarifa::class,
            'fecha_viaje' => 'date',
            'fecha_confirmacion' => 'datetime',
            'm3_registrado' => 'integer',
            'm3_confirmado' => 'integer',
            'valor_cobro_unitario' => 'integer',
            'valor_material_m3' => 'integer',
            'material_por_conductor' => 'boolean',
            'valor_pago_unitario' => 'integer',
            'valor_base_conductor' => 'integer',
            'valor_pago_conductor' => 'integer',
            'porcentaje_conductor' => 'decimal:2',
        ];
    }

    /**
     * El bloqueo por liquidación vive aquí y no en el controlador: cualquier
     * ruta que toque el viaje (una corrección masiva, un comando de consola)
     * queda cubierta.
     */
    protected static function booted(): void
    {
        static::updating(function (Viaje $viaje) {
            if (! $viaje->estaLiquidado()) {
                return;
            }

            // Lo único que puede cambiar en un viaje liquidado es su propia
            // asociación a la liquidación (al generarla o al reabrirla).
            $permitidos = ['liquidacion_cobro_id', 'liquidacion_pago_id', 'liquidacion_material_id', 'updated_at'];

            if (array_diff(array_keys($viaje->getDirty()), $permitidos) !== []) {
                throw new ViajeBloqueado(
                    "El viaje {$viaje->id} está en una liquidación y no se puede modificar."
                );
            }
        });

        static::deleting(function (Viaje $viaje) {
            throw new ViajeBloqueado(
                'Los viajes no se borran: se anulan para conservar el histórico.'
            );
        });
    }

    public function volqueta(): BelongsTo
    {
        return $this->belongsTo(Volqueta::class);
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'conductor_id');
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class);
    }

    public function canteraMaterial(): BelongsTo
    {
        return $this->belongsTo(CanteraMaterial::class);
    }

    public function botadero(): BelongsTo
    {
        return $this->belongsTo(Botadero::class);
    }

    public function vale(): BelongsTo
    {
        return $this->belongsTo(Vale::class);
    }

    public function liquidacionCobro(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_cobro_id');
    }

    public function liquidacionPago(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_pago_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    // --- Estado -------------------------------------------------------

    public function estaConfirmado(): bool
    {
        return $this->estado === EstadoViaje::Confirmado;
    }

    public function estaLiquidado(): bool
    {
        return $this->liquidacion_cobro_id !== null
            || $this->liquidacion_pago_id !== null
            || $this->liquidacion_material_id !== null;
    }

    public function esSuministro(): bool
    {
        return $this->tipo_viaje === TipoViaje::Suministro;
    }

    // --- Dinero -------------------------------------------------------

    /** Los m³ que mandan: los del vale si ya se confirmó, si no los registrados. */
    public function m3Efectivos(): int
    {
        return $this->m3_confirmado ?? $this->m3_registrado;
    }

    /** Lo que paga el consorcio: el flete de la tarifa más el material. */
    public function totalCobro(): int
    {
        return $this->totalFlete() + $this->totalMaterial();
    }

    public function totalFlete(): int
    {
        return $this->aplicar($this->unidad_cobro, $this->valor_cobro_unitario);
    }

    /** El material va al mismo precio de la cantera: se cobra y se le paga igual. */
    public function totalMaterial(): int
    {
        return (int) ($this->valor_material_m3 ?? 0) * $this->m3Efectivos();
    }

    /** Lo que se le debe a la cantera: nada si el material lo puso el conductor. */
    public function totalMaterialCantera(): int
    {
        return $this->material_por_conductor ? 0 : $this->totalMaterial();
    }

    /** El material que el conductor consiguió o compró: se le devuelve en su cuenta de pago. */
    public function totalReembolsoMaterial(): int
    {
        return $this->material_por_conductor ? $this->totalMaterial() : 0;
    }

    /** Lo que se le paga al dueño de la volqueta. Cero si la volqueta es propia. */
    public function totalPagoPropietario(): int
    {
        return $this->aplicar($this->unidad_pago, $this->valor_pago_unitario);
    }

    /** Lo que se le paga al conductor de una volqueta propia. */
    public function totalPagoConductor(): int
    {
        return (int) ($this->valor_pago_conductor ?? 0);
    }

    /** El material entra y sale al mismo precio: el margen es solo del flete. */
    public function margen(): int
    {
        return $this->totalFlete() - $this->totalPagoPropietario() - $this->totalPagoConductor();
    }

    private function aplicar(?UnidadTarifa $unidad, ?int $valor): int
    {
        if ($unidad === null || $valor === null) {
            return 0;
        }

        return $unidad === UnidadTarifa::M3
            ? $valor * $this->m3Efectivos()
            : $valor;
    }

    // --- Scopes -------------------------------------------------------

    public function scopePorConfirmar(Builder $query): Builder
    {
        return $query->where('estado', EstadoViaje::PorConfirmar);
    }

    public function scopeConfirmados(Builder $query): Builder
    {
        return $query->where('estado', EstadoViaje::Confirmado);
    }

    public function scopeSinLiquidarPago(Builder $query): Builder
    {
        return $query->whereNull('liquidacion_pago_id');
    }

    public function scopeSinLiquidarCobro(Builder $query): Builder
    {
        return $query->whereNull('liquidacion_cobro_id');
    }

    public function scopeSinLiquidarMaterial(Builder $query): Builder
    {
        return $query->whereNull('liquidacion_material_id');
    }

    /** Los que se le deben a la cantera: el material no lo puso el conductor. */
    public function scopeMaterialDeCantera(Builder $query): Builder
    {
        return $query->where('material_por_conductor', false);
    }

    public function scopeEntreFechas(Builder $query, string $desde, string $hasta): Builder
    {
        return $query->whereBetween('fecha_viaje', [$desde, $hasta]);
    }

    public function scopeDeLaObra(Builder $query, int $obraId): Builder
    {
        return $query->where('obra_id', $obraId);
    }

    /** Viajes cuyo pago le corresponde a este tercero como dueño de la volqueta. */
    public function scopeDelPropietario(Builder $query, int $terceroId): Builder
    {
        return $query->whereHas('volqueta', fn (Builder $q) => $q
            ->where('propietario_id', $terceroId)
            ->where('es_propia', false)
        );
    }

    /** Viajes de volquetas propias conducidos por este tercero. */
    public function scopeDelConductorPropio(Builder $query, int $terceroId): Builder
    {
        return $query
            ->where('conductor_id', $terceroId)
            ->whereHas('volqueta', fn (Builder $q) => $q->where('es_propia', true));
    }
}
