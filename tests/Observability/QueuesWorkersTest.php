<?php

use Illuminate\Support\Facades\Route;
use Innertia\Observability\Http\Controllers\QueuesController;
use Innertia\Telemetry\Health\WorkerHeartbeat;

beforeEach(function () {
    Route::get('t/queues/workers', [QueuesController::class, 'workers']);
    Route::post('t/queues/restart', [QueuesController::class, 'restart']);
});

it('workers devuelve los latidos + tamaños de cola', function () {
    (new WorkerHeartbeat)->ping('redis');

    $body = $this->getJson('/t/queues/workers')->assertOk()->json();

    expect($body)->toHaveKeys(['workers', 'queue_sizes'])
        ->and($body['workers'])->toHaveCount(1)
        ->and($body['queue_sizes'])->toHaveKeys(['agents', 'default', 'telemetry']);
});

it('restart responde ok (señal graceful)', function () {
    $this->postJson('/t/queues/restart')->assertOk()->assertJson(['ok' => true]);
});
