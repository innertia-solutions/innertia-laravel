<?php

namespace Innertia\Telemetry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Innertia\DataTable\DataTable;
use Innertia\Telemetry\Collectors\DataTableCollector;
use Innertia\Telemetry\Collectors\EventCollector;
use Innertia\Telemetry\Collectors\ExceptionCollector;
use Innertia\Telemetry\Collectors\LogCollector;
use Innertia\Telemetry\Collectors\QueryCollector;
use Innertia\Telemetry\Http\Middleware\TelemetryMiddleware;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/innertia.php', 'innertia');

        $this->app->singleton(\Innertia\Telemetry\Support\PayloadSanitizer::class, function () {
            return new \Innertia\Telemetry\Support\PayloadSanitizer(
                config('innertia.telemetry.redact', [])
            );
        });
        $this->app->singleton(\Innertia\Telemetry\Store\TelemetryStore::class);
        $this->app->singleton(\Innertia\Telemetry\Cases\CaseFingerprinter::class);
        $this->app->singleton(\Innertia\Telemetry\Cases\CaseRecorder::class);
        $this->app->singleton(\Innertia\Telemetry\TelemetryTags::class);

        // El collector se resuelve SIEMPRE (aunque la captura esté apagada): los
        // endpoints de beacon/presencia lo necesitan para etiquetar por trace_id.
        $this->app->singleton(TelemetryCollector::class, function () {
            return new TelemetryCollector(
                appName:   config('innertia.telemetry.app_name', config('app.name', 'app')),
                sessionId: $this->resolveSessionId(),
                tenant:    $this->resolveTenant(),
                env:       app()->environment(),
            );
        });

        $this->app->singleton(TelemetryManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Innertia\Telemetry\Console\PruneTelemetryCommand::class,
            ]);
        }

        // Telemetría self-contained: las tablas se cargan SIEMPRE en la DB del producto
        // (independiente del gate de captura runtime).
        $appMode = in_array(config('innertia.mode'), ['saas', 'open']) ? 'saas' : 'app';
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/telemetry/' . $appMode);

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
        $this->loadViewsFrom(__DIR__ . '/Mail/views', 'innertia-telemetry');

        // Servidor MCP (stdio + HTTP opcional) — solo si laravel/mcp está instalado.
        if (class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            $this->loadRoutesFrom(__DIR__ . '/Mcp/routes.php');
        }

        if (! $this->shouldActivate()) {
            return;
        }

        // Registrar middleware en grupo api
        $this->app['router']->pushMiddlewareToGroup('api', TelemetryMiddleware::class);

        // Hooks de captura
        $this->registerQueryCapture();
        $this->registerLogCapture();
        $this->registerEventCapture();
        $this->registerDataTableCapture();
        $this->registerExceptionCapture();
        $this->registerMailCapture();
        $this->registerWorkerHeartbeat();

        \Illuminate\Foundation\AliasLoader::getInstance()->alias(
            'Telemetry',
            \Innertia\Facades\Telemetry::class
        );
    }

    private function shouldActivate(): bool
    {
        // La captura runtime se activa en TODOS los entornos cuando enabled=true
        // (incluye producción: es un motor de tracking de errores). El volumen se
        // acota con los flags capture.* y la retención. El gate corre en boot(),
        // por eso no depende del usuario autenticado (que aún no existe ahí).
        return (bool) config('innertia.telemetry.enabled', false);
    }

    private function resolveSessionId(): string
    {
        try {
            return auth()->payload()->get('jti')
                ?? \Illuminate\Support\Str::uuid()->toString();
        } catch (\Throwable) {
            return 'cli-' . \Illuminate\Support\Str::uuid()->toString();
        }
    }

    private function resolveTenant(): ?string
    {
        try {
            return app(\Innertia\InnertiaManager::class)->tenant()?->key ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function registerWorkerHeartbeat(): void
    {
        // Cada loop del worker escribe su latido (throttleado a ~5s) para que el panel
        // sepa qué workers están vivos. Solo corre dentro de un proceso worker.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\Looping::class, function ($event) {
            static $last = 0;
            $now = time();
            if ($now - $last < 5) return;
            $last = $now;
            try {
                $this->app->make(\Innertia\Telemetry\Health\WorkerHeartbeat::class)
                    ->ping($event->connectionName ?? null);
            } catch (\Throwable) {
                // best-effort: no romper el worker por el heartbeat
            }
        });
    }

    private function registerQueryCapture(): void
    {
        if (! config('innertia.telemetry.capture.queries', true)) return;

        DB::listen(function ($query) {
            $collector = $this->app->make(TelemetryCollector::class);
            QueryCollector::handle(
                $collector,
                $query->sql,
                $query->bindings,
                $query->time,
                $query->connectionName,
            );
        });
    }

    private function registerLogCapture(): void
    {
        if (! config('innertia.telemetry.capture.logs', true)) return;

        Log::listen(function ($message) {
            $collector = $this->app->make(TelemetryCollector::class);
            LogCollector::handle(
                $collector,
                $message->level,
                (string) $message->message,
                is_array($message->context) ? $message->context : [],
            );
        });
    }

    private function registerEventCapture(): void
    {
        if (! config('innertia.telemetry.capture.events', true)) return;

        Event::listen('*', function (string $eventName, array $payload) {
            $collector = $this->app->make(TelemetryCollector::class);
            EventCollector::handle($collector, $eventName, $payload);
        });
    }

    private function registerDataTableCapture(): void
    {
        if (! config('innertia.telemetry.capture.datatables', true)) return;

        DataTable::$onRender = function (string $tableName, int $rowCount, float $durationMs) {
            $collector = $this->app->make(TelemetryCollector::class);
            DataTableCollector::handle($collector, $tableName, $rowCount, $durationMs);
        };
    }

    private function registerMailCapture(): void
    {
        if (! config('innertia.telemetry.capture.mail', true)) return;

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            function ($event) {
                $collector = $this->app->make(TelemetryCollector::class);
                \Innertia\Telemetry\Collectors\MailCollector::handle($collector, $event->message);
            }
        );
    }

    private function registerExceptionCapture(): void
    {
        if (! config('innertia.telemetry.capture.exceptions', true)) return;

        try {
            $this->app->extend(
                \Illuminate\Contracts\Debug\ExceptionHandler::class,
                function ($handler) {
                    if ($handler instanceof TelemetryExceptionHandler) {
                        return $handler;
                    }

                    // Handler estándar de Laravel (el que configura withExceptions()):
                    // se reemplaza por la subclase con captura, conservando su estado.
                    if (get_class($handler) === \Illuminate\Foundation\Exceptions\Handler::class) {
                        return TelemetryExceptionHandler::from($handler, $this->app);
                    }

                    // Handler propio de la app: no se envuelve (envolver rompe su render).
                    // Se engancha la captura como reportable cuando es posible.
                    if ($handler instanceof \Illuminate\Foundation\Exceptions\Handler) {
                        $handler->reportable(function (\Throwable $e) {
                            try {
                                $collector = app(TelemetryCollector::class);
                                ExceptionCollector::handle($collector, $e);
                            } catch (\Throwable) {}
                        });
                    }

                    return $handler;
                }
            );
        } catch (\Throwable) {
            // La app ya resolvió el handler, no se puede extender
        }
    }
}
