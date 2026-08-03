<?php

use Innertia\Telemetry\Http\Middleware\TelemetryMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('terminate no lanza aunque handle no haya corrido en esta instancia', function () {
    $mw = new TelemetryMiddleware();          // instancia fresca, sin handle()
    $request = Request::create('/x', 'GET');
    $response = new Response('ok');
    $mw->terminate($request, $response);      // no debe lanzar
    expect(true)->toBeTrue();
});
