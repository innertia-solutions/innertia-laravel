<?php

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\Collectors\RequestCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('creates a request event', function () {
    $collector = new TelemetryCollector('app', 'sess-1', 'acme', 'local');
    $request   = Request::create('/api/users', 'GET');
    $response  = new Response('ok', 200);

    RequestCollector::handle($collector, $request, $response, 45.0);

    $batch = $collector->batch();
    expect($batch)->toHaveCount(1)
        ->and($batch[0]->type)->toBe('request')
        ->and($batch[0]->payload['method'])->toBe('GET')
        ->and($batch[0]->payload['path'])->toBe('api/users')
        ->and($batch[0]->payload['status'])->toBe(200)
        ->and($batch[0]->durationMs)->toBe(45.0);
});
