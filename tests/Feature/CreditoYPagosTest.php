<?php

use App\Actions\Liquidaciones\GenerarLiquidacion;
use App\Actions\Viajes\RegistrarViajes;
use App\Enums\TipoLiquidacion;
use App\Enums\TipoViaje;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->e = escenarioBase();
});

it('el material que puso el conductor se le debe a él y no a la cantera', function () {
    $viajes = app(RegistrarViajes::class)(
        cabecera: [
            'obra_id' => $this->e['obra']->id,
            'tipo_viaje' => TipoViaje::Suministro->value,
            'fecha_viaje' => '2026-08-25',
            'cantera_material_id' => $this->e['cantera_material']->id,
        ],
        filas: [
            ['volqueta_id' => $this->e['volqueta']->id, 'm3' => 14],
            ['volqueta_id' => $this->e['volqueta']->id, 'm3' => 14, 'material_por_conductor' => true],
        ],
        usuario: $this->e['usuario'],
    );
    confirmarTodos($this->e, $viajes);

    $material = app(GenerarLiquidacion::class)(TipoLiquidacion::Material, null, null, '2026-08-01', '2026-08-31', canteraId: $this->e['cantera']->id);
    $pago = app(GenerarLiquidacion::class)(TipoLiquidacion::Pago, null, $this->e['propietario']->id, '2026-08-01', '2026-08-31');
    $cobro = app(GenerarLiquidacion::class)(TipoLiquidacion::Cobro, $this->e['consorcio']->id, null, '2026-08-01', '2026-08-31');

    // A la cantera, solo un viaje: 14 m³ x 10.000.
    expect($material->viajes)->toHaveCount(1)
        ->and($material->saldo)->toBe(140_000)
        // Al dueño, dos fletes de 200.000 más los 140.000 del material que puso.
        ->and($pago->total_bruto)->toBe(540_000)
        // Al consorcio se le cobra igual: 2 x 14 x (25.000 + 10.000).
        ->and($cobro->total_bruto)->toBe(980_000);
});

it('el número de la cuenta lo puede poner el usuario y no se repite', function () {
    confirmarTodos($this->e, registrarViajes($this->e));

    $this->actingAs($this->e['usuario'])->postJson('/api/liquidaciones', [
        'tipo' => 'cobro', 'consorcio_id' => $this->e['consorcio']->id,
        'desde' => '2026-08-01', 'hasta' => '2026-08-31', 'numero_cuenta' => 13,
    ])->assertCreated()->assertJsonPath('numero_cuenta', 13);

    confirmarTodos($this->e, registrarViajes($this->e, fecha: '2026-09-02'), numeroVale: 1600);

    $this->actingAs($this->e['usuario'])->postJson('/api/liquidaciones', [
        'tipo' => 'cobro', 'consorcio_id' => $this->e['consorcio']->id,
        'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'numero_cuenta' => 13,
    ])->assertUnprocessable()->assertJsonValidationErrors('numero_cuenta');

    // Sin número sigue la numeración: la 14.
    $id = $this->actingAs($this->e['usuario'])->postJson('/api/liquidaciones', [
        'tipo' => 'cobro', 'consorcio_id' => $this->e['consorcio']->id,
        'desde' => '2026-09-01', 'hasta' => '2026-09-30',
    ])->assertCreated()->assertJsonPath('numero_cuenta', 14)->json('id');

    $this->actingAs($this->e['usuario'])->patchJson("/api/liquidaciones/{$id}/numero", ['numero_cuenta' => 20])
        ->assertOk()->assertJsonPath('data.numero_cuenta', 20);
});

it('el anticipo del consorcio llega sin cuenta, con comprobante, y se aplica después', function () {
    $pago = $this->actingAs($this->e['admin'])->post('/api/pagos', [
        'consorcio_id' => $this->e['consorcio']->id,
        'fecha' => '2026-08-01',
        'valor' => 300_000,
        'numero_comprobante' => 'CI-0045',
        'archivo' => UploadedFile::fake()->create('comprobante.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('tiene_comprobante', true)
        ->assertJsonPath('cuenta', null)
        ->json();

    $this->actingAs($this->e['admin'])->get("/api/anticipos/{$pago['id']}/comprobante")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    confirmarTodos($this->e, registrarViajes($this->e, veces: 2));
    $cuenta = app(GenerarLiquidacion::class)(TipoLiquidacion::Cobro, $this->e['consorcio']->id, null, '2026-08-01', '2026-08-31');

    $this->actingAs($this->e['admin'])->putJson("/api/anticipos/{$pago['id']}/cuenta", ['liquidacion_id' => $cuenta->id])
        ->assertOk()->assertJsonPath('cuenta.id', $cuenta->id);

    // 2 x 14 x 35.000 = 980.000, menos el anticipo.
    expect($cuenta->fresh()->saldo)->toBe(680_000);

    $this->actingAs($this->e['admin'])->getJson('/api/pagos?consorcio_id='.$this->e['consorcio']->id)
        ->assertOk()->assertJsonPath('total', 300_000)->assertJsonPath('data.0.numero_comprobante', 'CI-0045');
});

it('la relación de material marca en rojo los viajes sin vale y resta los abonos', function () {
    confirmarTodos($this->e, registrarViajes($this->e, veces: 2));
    registrarViajes($this->e); // sin vale todavía

    $material = app(GenerarLiquidacion::class)(TipoLiquidacion::Material, null, null, '2026-08-01', '2026-08-31', canteraId: $this->e['cantera']->id);
    $this->actingAs($this->e['admin'])->postJson("/api/liquidaciones/{$material->id}/anticipos", ['fecha' => '2026-08-28', 'valor' => 100_000])->assertCreated();

    $relacion = $this->actingAs($this->e['admin'])->getJson('/api/reportes/material?'.http_build_query([
        'cantera_id' => $this->e['cantera']->id, 'desde' => '2026-08-01', 'hasta' => '2026-08-31',
    ]))->assertOk()->json();

    $celda = $relacion['filas'][0]['celdas'][$this->e['obra']->id];

    expect($celda['viajes'])->toBe(3)
        ->and($celda['sin_vale'])->toBe(1)
        ->and($celda['cuadra'])->toBeFalse()
        // Solo lo confirmado se le debe: 2 x 14 x 10.000.
        ->and($relacion['totales']['compra'])->toBe(280_000)
        ->and($relacion['totales']['abonado'])->toBe(100_000)
        ->and($relacion['totales']['saldo'])->toBe(180_000);
});

it('el detalle de una cuenta trae sus viajes con el vale', function () {
    confirmarTodos($this->e, registrarViajes($this->e, veces: 2));
    $cuenta = app(GenerarLiquidacion::class)(TipoLiquidacion::Cobro, $this->e['consorcio']->id, null, '2026-08-01', '2026-08-31');

    $this->actingAs($this->e['usuario'])->getJson("/api/liquidaciones/{$cuenta->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.viajes')
        ->assertJsonPath('data.viajes.0.vale', 1531)
        ->assertJsonPath('data.viajes.0.cantera', 'Fénix - Orión');
});
