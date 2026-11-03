<?php

use App\Models\Cantera;

it('una cantera desactivada por error se puede reactivar', function () {
    $e = escenarioBase();
    $cantera = Cantera::first();

    $this->actingAs($e['admin'])->deleteJson("/api/canteras/{$cantera->id}")->assertOk();
    expect($cantera->fresh()->activo)->toBeFalse();

    $this->actingAs($e['admin'])->postJson("/api/canteras/{$cantera->id}/reactivar")->assertOk();
    expect($cantera->fresh()->activo)->toBeTrue();
});
