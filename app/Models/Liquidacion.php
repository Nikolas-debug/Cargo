<?php

namespace App\Models;

use App\Enums\EstadoLiquidacion;
use App\Enums\TipoLiquidacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Las "CUENTAS" del Excel: total bruto - anticipos = saldo.
 */
class Liquidacion extends Model
{
    use HasFactory;

    protected $table = 'liquidaciones';

    protected $fillable = [
        'tipo', 'numero_cuenta', 'consorcio_id', 'tercero_id', 'cantera_id',
        'fecha_inicio', 'fecha_fin', 'total_bruto', 'total_anticipos',
        'saldo', 'estado', 'cerrada_por', 'cerrada_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoLiquidacion::class,
            'estado' => EstadoLiquidacion::class,
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'cerrada_en' => 'datetime',
            'numero_cuenta' => 'integer',
            'total_bruto' => 'integer',
            'total_anticipos' => 'integer',
            'saldo' => 'integer',
        ];
    }

    public function consorcio(): BelongsTo
    {
        return $this->belongsTo(Consorcio::class);
    }

    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }

    public function cantera(): BelongsTo
    {
        return $this->belongsTo(Cantera::class);
    }

    public function anticipos(): HasMany
    {
        return $this->hasMany(Anticipo::class);
    }

    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    /** Los viajes que agrupa, según el tipo de cuenta. */
    public function viajes(): HasMany
    {
        // Sin tipo es la carga anticipada (load, with, refresh): adivinar la
        // columna traería los viajes de otra cuenta. Mejor fallar.
        if ($this->tipo === null) {
            throw new \LogicException('Los viajes de una cuenta se cargan con cargarViajes(), no con load(), with() ni refresh().');
        }

        return $this->hasMany(Viaje::class, self::columnaEnViajes($this->tipo));
    }

    /**
     * Carga los viajes desde esta cuenta. Nunca con load('viajes'): la carga
     * anticipada arma la relación sobre un modelo vacío, sin tipo, y no sabe
     * qué columna mirar.
     *
     * @param  array<int, string>  $con  relaciones de cada viaje
     */
    public function cargarViajes(array $con = []): self
    {
        return $this->setRelation('viajes', $this->viajes()->with($con)->get());
    }

    /** La columna de viajes que apunta a una cuenta de este tipo. */
    public static function columnaEnViajes(TipoLiquidacion $tipo): string
    {
        return match ($tipo) {
            TipoLiquidacion::Cobro => 'liquidacion_cobro_id',
            TipoLiquidacion::Pago => 'liquidacion_pago_id',
            TipoLiquidacion::Material => 'liquidacion_material_id',
        };
    }

    public function esCobro(): bool
    {
        return $this->tipo === TipoLiquidacion::Cobro;
    }

    public function esMaterial(): bool
    {
        return $this->tipo === TipoLiquidacion::Material;
    }

    public function estaAbierta(): bool
    {
        return $this->estado === EstadoLiquidacion::Abierta;
    }

    /**
     * Vuelve a sumar todo desde los viajes y los anticipos.
     * Se llama al generar la cuenta y cada vez que cambia un anticipo.
     */
    public function recalcularTotales(): self
    {
        $bruto = $this->viajes->sum(fn (Viaje $viaje) => match ($this->tipo) {
            TipoLiquidacion::Cobro => $viaje->totalCobro(),
            // Si el conductor puso el material, se le devuelve aquí.
            TipoLiquidacion::Pago => $viaje->totalPagoPropietario() + $viaje->totalPagoConductor() + $viaje->totalReembolsoMaterial(),
            TipoLiquidacion::Material => $viaje->totalMaterialCantera(),
        });

        $anticipos = (int) $this->anticipos()->sum('valor');

        $this->total_bruto = (int) $bruto;
        $this->total_anticipos = $anticipos;
        $this->saldo = (int) $bruto - $anticipos;

        return $this;
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->where('estado', EstadoLiquidacion::Abierta);
    }

    /** Regla de validación: ese número no lo tiene ya otra cuenta del mismo tipo y destinatario. */
    public static function numeroLibre(?string $tipo, ?int $consorcioId, ?int $terceroId, ?int $canteraId, ?int $ignorar = null): Unique
    {
        return Rule::unique('liquidaciones', 'numero_cuenta')
            ->where('tipo', $tipo)
            ->where('consorcio_id', $consorcioId)
            ->where('tercero_id', $terceroId)
            ->where('cantera_id', $canteraId)
            ->ignore($ignorar);
    }

    /** El siguiente número de cuenta para este destinatario. */
    public static function siguienteNumero(TipoLiquidacion $tipo, ?int $consorcioId, ?int $terceroId, ?int $canteraId = null): int
    {
        return (int) static::query()
            ->where('tipo', $tipo)
            ->when($consorcioId, fn (Builder $q) => $q->where('consorcio_id', $consorcioId))
            ->when($terceroId, fn (Builder $q) => $q->where('tercero_id', $terceroId))
            ->when($canteraId, fn (Builder $q) => $q->where('cantera_id', $canteraId))
            ->max('numero_cuenta') + 1;
    }
}
