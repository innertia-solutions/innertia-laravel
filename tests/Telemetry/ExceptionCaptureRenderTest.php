<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\InnertiaExceptionHandler;
use Innertia\Telemetry\TelemetryServiceProvider;

/**
 * Reproduce el orden de arranque de una app real bajo php-fpm:
 * bootstrap/app.php registra withExceptions() (afterResolving de Handler::class) y la
 * captura de excepciones de Telemetry se registra ANTES de que el handler se resuelva.
 * Los render callbacks de la app deben seguir aplicándose al renderizar.
 */
function bootHandlerLikeFpm($app): ExceptionHandler
{
    $app->forgetInstance(ExceptionHandler::class);
    $app->singleton(ExceptionHandler::class, Handler::class);
    $app->afterResolving(Handler::class, function ($handler) {
        InnertiaExceptionHandler::register(new Exceptions($handler));
    });

    // Igual que TelemetryServiceProvider::boot(), antes de resolver el handler.
    $provider = new TelemetryServiceProvider($app);
    (fn () => $this->registerExceptionCapture())->call($provider);

    return $app->make(ExceptionHandler::class);
}

it('aplica los render callbacks de la app con la captura de telemetry activa (orden fpm)', function () {
    config()->set('innertia.telemetry.capture.exceptions', true);
    $handler = bootHandlerLikeFpm($this->app);

    $request = Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $response = $handler->render($request, new ForbiddenException('No tienes permiso.'));

    expect($response->getStatusCode())->toBe(403);
    expect(json_decode($response->getContent(), true)['message'])->toBe('No tienes permiso.');
});

it('sigue capturando el reporte en telemetry', function () {
    config()->set('innertia.telemetry.capture.exceptions', true);
    $handler = bootHandlerLikeFpm($this->app);
    $collector = app(\Innertia\Telemetry\TelemetryCollector::class);
    $collector->flush();

    $handler->report(new RuntimeException('boom'));

    $events = collect($collector->batch())->filter(fn ($e) => ($e->type ?? $e['type'] ?? null) === 'exception');
    expect($events)->not->toBeEmpty();
});

it('conserva la configuración si el handler ya estaba resuelto (orden CLI/tests)', function () {
    config()->set('innertia.telemetry.capture.exceptions', true);
    $this->app->forgetInstance(ExceptionHandler::class);
    $this->app->singleton(ExceptionHandler::class, Handler::class);
    $this->app->afterResolving(Handler::class, fn ($h) => InnertiaExceptionHandler::register(new Exceptions($h)));
    $this->app->make(ExceptionHandler::class);           // resuelto y configurado ANTES del extend

    $provider = new TelemetryServiceProvider($this->app);
    (fn () => $this->registerExceptionCapture())->call($provider);

    $handler = $this->app->make(ExceptionHandler::class);
    $response = $handler->render(
        Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
        new ForbiddenException('No tienes permiso.'),
    );
    expect($handler)->toBeInstanceOf(\Innertia\Telemetry\TelemetryExceptionHandler::class);
    expect($response->getStatusCode())->toBe(403);
});
