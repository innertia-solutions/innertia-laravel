<?php

namespace Innertia\Telemetry;

use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Exceptions\Handler;
use Innertia\Telemetry\Cases\CaseRecorder;
use Innertia\Telemetry\Collectors\ExceptionCollector;
use Throwable;

/**
 * Exception handler de Laravel + captura de telemetry en report().
 *
 * Es una SUBCLASE (no un decorador): toda la configuración que la app registra con
 * withExceptions() (render/report callbacks, dontReport, map, levels…) vive en esta
 * misma instancia, así que render() la respeta sin importar en qué orden se resolvió el
 * handler. El decorador anterior delegaba render() a un handler interno sin esos
 * callbacks y, bajo php-fpm, las excepciones de la lib salían como 500.
 */
class TelemetryExceptionHandler extends Handler
{
    /** Crea el handler copiando el estado (configuración ya aplicada) de un Handler existente. */
    public static function from(Handler $original, Container $container): self
    {
        $new = new self($container);

        // Cerrado en el scope de Handler: puede leer/escribir sus props protegidas y privadas.
        (function () use ($new) {
            foreach (get_object_vars($this) as $property => $value) {
                $new->{$property} = $value;
            }
        })->call($original);

        return $new;
    }

    public function report(Throwable $e): void
    {
        parent::report($e);

        try {
            $collector = app(TelemetryCollector::class);
            ExceptionCollector::handle($collector, $e);

            // 'except' solo excluye la PROMOCIÓN a caso (no la captura del evento crudo del
            // trace, que ya ocurrió arriba a propósito).
            foreach (config('innertia.telemetry.except', []) as $class) {
                if ($e instanceof $class) {
                    return;
                }
            }

            app(CaseRecorder::class)->record($e, $collector->traceId());
        } catch (Throwable) {
            // La telemetry nunca debe romper el reporte de errores.
        }
    }
}
