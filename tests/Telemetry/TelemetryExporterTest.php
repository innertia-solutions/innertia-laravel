<?php

use Innertia\Telemetry\TelemetryExporter;
use Innertia\Telemetry\TelemetryEvent;
use Mockery as m;

it('does nothing when batch is empty', function () {
    $exporter = new TelemetryExporter('http://olimpo:8000', 'key-abc', 'my-app');
    $exporter->flush([]);

    // Should not throw and just return
    expect(true)->toBeTrue();
});

it('does nothing when olimpo_url is not configured', function () {
    $exporter = new TelemetryExporter(null, 'key-abc', 'my-app');
    $event = new TelemetryEvent(
        type: 'query',
        payload: ['sql' => 'select 1'],
        context: ['tenant' => null, 'user_id' => null, 'route' => 'GET /', 'env' => 'local'],
    );

    $exporter->flush([$event]);

    // Should not throw and just return
    expect(true)->toBeTrue();
});

it('sends batch as JSON to olimpo telemetry endpoint', function () {
    // Create a mock pending request
    $mockRequest = m::mock('Illuminate\Http\Client\PendingRequest');
    $mockRequest
        ->shouldReceive('timeout')
        ->with(3)
        ->andReturnSelf();
    $mockRequest
        ->shouldReceive('withHeader')
        ->with('X-Olimpo-Key', 'key-abc')
        ->andReturnSelf();
    $mockRequest
        ->shouldReceive('post')
        ->once()
        ->with(
            'http://olimpo:8000/olimpo/telemetry',
            m::on(function ($data) {
                return $data['app'] === 'my-app'
                    && $data['session_id'] === 'sess-abc'
                    && count($data['events']) === 1
                    && $data['events'][0]['type'] === 'log';
            })
        )
        ->andReturn(m::mock('Illuminate\Http\Client\Response'));

    // Replace the HTTP facade with our mock
    \Illuminate\Support\Facades\Http::shouldReceive('timeout')
        ->with(3)
        ->andReturn($mockRequest);

    $exporter = new TelemetryExporter('http://olimpo:8000', 'key-abc', 'my-app');
    $event = new TelemetryEvent(
        type: 'log',
        payload: ['level' => 'info', 'message' => 'hello'],
        context: ['tenant' => 'acme', 'user_id' => null, 'route' => 'GET /test', 'env' => 'local'],
    );

    $exporter->flush([$event], 'sess-abc');

    expect(true)->toBeTrue();
});
