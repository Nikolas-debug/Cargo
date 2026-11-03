<?php

use App\Actions\Liquidaciones\CerrarLiquidacion;
use App\Actions\Liquidaciones\GenerarLiquidacion;
use App\Actions\Liquidaciones\RegistrarAnticipo;
use App\Actions\Vales\ConfirmarViajesPorVale;
use App\Actions\Viajes\AnularViaje;
use App\Enums\EstadoLiquidacion;
use App\Enums\TipoLiquidacion;
use App\Exceptions\LiquidacionCerrada;
use App\Exceptions\ViajeBloqueado;
use App\Models\Viaje;

beforeEach(function () {
    $this->escenario = escenarioBase();
    $this->viajes = registrarViajes($this->escenario, veces: 3);
});

it('solo mete en la cuenta los viajes confirmados', function () {
    // Se confirman dos de los tres.
    app(ConfirmarViajesPorVale::class)(
        datosVale: ['numero' => 1531, 'obra_id' => $this->escenario['obra']->id, 'fecha_vale' => '2026-08-25'],
        viajes: $this->viajes->take(2)->map(fn (Viaje $v) => ['viaje_id' => $v->id])->all(),
        usuario: $this->escenario['usuario'],
    );

    $liquidacion = app(GenerarLiquidacion::class)(
        TipoLiquidacion::Pago,
        consorcioId: null,
        terceroId: $this->escenario['propietario']->id,
        desde: '2026-08-01',
        hasta: '2026-08-31',
    );

    expect($liquidacion->viajes)->toHaveCount(2)
        // 2 viajes x 200.000 por viaje.
        ->and($liquidacion->total_bruto)->toBe(400_000)
        ->and($liquidacion->saldo)->toBe(400_000)
        ->and($liquidacion->estado)->toBe(EstadoLiquidacion::Abierta);

    // El tercero sigue esperando su vale.
    expect($this->viajes->last()->fresh()->liquidacion_pago_id)->toBeNull();
});

it('descuenta los anticipos del saldo', function () {
    confirmarTodos($this->escenario, $this->viajes);

    $liquidacion = app(GenerarLiquidacion::class)(
        TipoLiquidacion::Pago,
        null,
        $this->escenario['propietario']->id,
        '2026-08-01',
        '2026-08-31',
    );

    expect($liquidacion->total_bruto)->toBe(600_000);

    app(RegistrarAnticipo::class)(
        $liquidacion,
        ['fecha' => '2026-08-13', 'valor' => 250_000, 'observacion' => 'Abono en efectivo'],
        $this->escenario['usuario'],
    );

    $liquidacion = $liquidacion->fresh();

    // Total bruto - anticipos = saldo, igual que el pie del Excel.
    expect($liquidacion->total_anticipos)->toBe(250_000)
        ->and($liquidacion->saldo)->toBe(350_000);
});

it('bloquea la edición de un viaje que ya entró en una cuenta', function () {
    confirmarTodos($this->escenario, $this->viajes);

    app(GenerarLiquidacion::class)(
        TipoLiquidacion::Pago,
        null,
        $this->escenario['propietario']->id,
        '2026-08-01',
        '2026-08-31',
    );

    $viaje = $this->viajes->first()->fresh();

    expect(fn () => $viaje->update(['m3_registrado' => 99]))
        ->toThrow(ViajeBloqueado::class);

    expect(fn () => app(AnularViaje::class)($viaje))
        ->toThrow(ViajeBloqueado::class);

    // Y tampoco se puede borrar: los viajes se anulan, no se borran.
    expect(fn () => $viaje->delete())->toThrow(ViajeBloqueado::class);
});

it('no acepta anticipos después de cerrar la cuenta', function () {
    confirmarTodos($this->escenario, $this->viajes);

    $liquidacion = app(GenerarLiquidacion::class)(
        TipoLiquidacion::Pago,
        null,
        $this->escenario['propietario']->id,
        '2026-08-01',
        '2026-08-31',
    );

    app(CerrarLiquidacion::class)($liquidacion, $this->escenario['admin']);

    expect($liquidacion->fresh()->estado)->toBe(EstadoLiquidacion::Cerrada);

    expect(fn () => app(RegistrarAnticipo::class)(
        $liquidacion->fresh(),
        ['fecha' => '2026-09-01', 'valor' => 100_000],
        $this->escenario['usuario'],
    ))->toThrow(LiquidacionCerrada::class);
});

it('numera las cuentas por destinatario', function () {
    confirmarTodos($this->escenario, $this->viajes);

    $primera = app(GenerarLiquidacion::class)(
        TipoLiquidacion::Pago,
        null,
        $this->escenario['propietario']->id,
        '2026-08-01',
        '2026-08-31',
    );

    expect($primera->numero_cuenta)->toBe(1);

    $mas = registrarViajes($this->escenario, veces: 1, fecha: '2026-09-02');
    confirmarTodos($this->escenario, $mas, numeroVale: 1600);

    $segunda = app(GenerarLiquidacion::class)(
        TipoLiquidacion::Pago,
        null,
        $this->escenario['propietario']->id,
        '2026-09-01',
        '2026-09-30',
    );

    expect($segunda->numero_cuenta)->toBe(2);
});

it('arma la cuenta de material de la cantera, a crédito', function () {
    confirmarTodos($this->escenario, $this->viajes);

    $liquidacion = app(GenerarLiquidacion::class)(
        TipoLiquidacion::Material,
        null,
        null,
        '2026-08-01',
        '2026-08-31',
        canteraId: $this->escenario['cantera']->id,
    );

    // 3 viajes x 14 m³ x 10.000: se le debe a la cantera hasta que se le abone.
    expect($liquidacion->viajes)->toHaveCount(3)
        ->and($liquidacion->total_bruto)->toBe(420_000)
        ->and($liquidacion->saldo)->toBe(420_000)
        ->and($this->viajes->first()->fresh()->liquidacion_material_id)->toBe($liquidacion->id);

    app(RegistrarAnticipo::class)($liquidacion, ['fecha' => '2026-08-30', 'valor' => 100_000], $this->escenario['admin']);
    expect($liquidacion->fresh()->saldo)->toBe(320_000);

    // La cuenta de cobro de esos viajes sigue libre, y lleva el material:
    // 3 x 14 m³ x (25.000 de flete + 10.000 de material).
    $cobro = app(GenerarLiquidacion::class)(
        TipoLiquidacion::Cobro,
        $this->escenario['consorcio']->id,
        null,
        '2026-08-01',
        '2026-08-31',
    );

    expect($cobro->viajes)->toHaveCount(3)
        ->and($cobro->total_bruto)->toBe(1_470_000);

    // Otra cantera no tiene viajes que liquidar.
    expect(fn () => app(GenerarLiquidacion::class)(
        TipoLiquidacion::Material,
        null,
        null,
        '2026-08-01',
        '2026-08-31',
        canteraId: $this->escenario['otraCantera']->id,
    ))->toThrow(Illuminate\Validation\ValidationException::class);
});
