<?php

use Illuminate\Support\Facades\Cache;
use Innertia\Telemetry\Health\WorkerHeartbeat;

it('marca un worker vivo tras pingear', function () {
    (new WorkerHeartbeat)->ping('redis');

    $list = (new WorkerHeartbeat)->list();

    expect($list)->toHaveCount(1)
        ->and($list[0]['alive'])->toBeTrue()
        ->and($list[0]['connection'])->toBe('redis');
});

it('marca stale un worker viejo', function () {
    // Inyectar un latido viejo directamente en el store.
    Cache::store()->put('innertia:workers', [
        'host:1' => ['id' => 'host:1', 'connection' => 'redis', 'ts' => now()->subMinutes(5)->timestamp],
    ], now()->addMinutes(10));

    $list = (new WorkerHeartbeat)->list(staleSeconds: 30);

    expect($list)->toHaveCount(1)
        ->and($list[0]['alive'])->toBeFalse();
});
