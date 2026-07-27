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
    }

    private function shouldActivate(): bool
    {
        if (! config('innertia.telemetry.enabled', false)) {
            return false;
        }

        // En local/testing siempre activo
        if (app()->isLocal() || app()->runningUnitTests()) {
            return true;
        }

        // En otros entornos: requiere permiso 'devtools' en el usuario autenticado
        try {
            $user = auth()->user();
            if (! $user) return false;
            if (method_exists($user, 'hasPermissionTo')) {
                return $user->hasPermissionTo('devtools');
            }
        } catch (\Throwable) {
            // No hay auth en este punto del ciclo
        }

        return false;
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
                    return new class($handler, $this->app) extends \Illuminate\Foundation\Exceptions\Handler {
                        public function __construct(
                            private $inner,
                            $container,
                        ) {
                            parent::__construct($container);
                        }

                        public function report(\Throwable $e): void
                        {
                            $this->inner->report($e);
                            try {
                                $collector = app(TelemetryCollector::class);
                                ExceptionCollector::handle($collector, $e);
                                // 'except' solo excluye la PROMOCIÓN a caso (no la captura del
                                // evento crudo del trace, que ya ocurrió arriba a propósito).
                                $except = config('innertia.telemetry.except', []);
                                foreach ($except as $class) {
                                    if ($e instanceof $class) return;
                                }
                                app(\Innertia\Telemetry\Cases\CaseRecorder::class)
                                    ->record($e, $collector->traceId());
                            } catch (\Throwable) {}
                        }

                        public function render($request, \Throwable $e)
                        {
                            return $this->inner->render($request, $e);
                        }
                    };
                }
            );
        } catch (\Throwable) {
            // La app ya resolvió el handler, no se puede extender
        }
    }
}
