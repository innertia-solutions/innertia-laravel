<?php

use Innertia\Observability\Database\PgStatStatements;

it('reporta disponibilidad sin lanzar y da razón cuando no está', function () {
    $available = PgStatStatements::available();
    expect($available)->toBeBool();
    if (! $available) {
        expect(PgStatStatements::reason())->toBeString();
    } else {
        expect(PgStatStatements::reason())->toBeNull();
    }
});
