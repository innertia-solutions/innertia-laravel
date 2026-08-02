<?php
use Innertia\Observability\Database\DatabaseQueries;

it('mantiene la forma y no lanza (filtro de ruido aplicado)', function () {
    $q = (new DatabaseQueries())->collect(5);
    expect($q)->toHaveKeys(['available', 'reason', 'top']);
    expect($q['top'])->toBeArray();
});
