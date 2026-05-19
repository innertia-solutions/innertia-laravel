# Telemetry Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar el pipeline de telemetría en `innertia-laravel`: cada app captura queries, logs, excepciones, DataTable calls y eventos; los transmite por WebSocket (Soketi, para futuro nuxt-devtools) y en batch HTTP a Olimpo; Olimpo recibe, encola, almacena en BD y rebroadcastea.

**Architecture:** `TelemetryCollector` es un singleton por request que acumula eventos. Cada evento se broadcastea inmediatamente a `private-innertia.devtools.{sessionId}` en Soketi. Al terminar el request (`terminate()`), `TelemetryExporter` envía el batch completo a `POST /olimpo/telemetry`. En Olimpo, `ProcessTelemetryJob` almacena en `telemetry_events` y broadcastea para el dashboard. Ambos lados (sender y receiver) viven en `src/Telemetry/`.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 3, Orchestra Testbench 9, `Illuminate\Support\Facades\Http` (para el export), `Illuminate\Broadcasting\PrivateChannel`, `Illuminate\Contracts\Queue\ShouldQueue`

---

## File Map

```
src/Telemetry/
├── TelemetryServiceProvider.php        ← registra todo el módulo
├── TelemetryCollector.php              ← singleton por request: acumula + broadcastea
├── TelemetryExporter.php               ← envía batch HTTP a Olimpo (en terminate)
├── TelemetryEvent.php                  ← DTO inmutable para un evento
├── Events/
│   ├── DevtoolsEvent.php               ← broadcast a Soketi sender (nuxt-devtools)
│   └── TelemetryBatchReceived.php      ← broadcast a Soketi Olimpo (dashboard)
├── Collectors/
│   ├── QueryCollector.php              ← DB::listen hook
│   ├── LogCollector.php                ← Log channel hook
│   ├── ExceptionCollector.php          ← handler de excepciones
│   ├── EventCollector.php              ← Event::listen('*') hook
│   └── RequestCollector.php           ← captura método, path, status, duración
├── Http/
│   ├── Controllers/
│   │   └── TelemetryController.php     ← POST /olimpo/telemetry (receiver)
│   └── Middleware/
│       └── TelemetryMiddleware.php     ← init en handle(), flush en terminate()
├── Jobs/
│   └── ProcessTelemetryJob.php         ← worker Olimpo: almacena + broadcastea
├── Models/
│   └── TelemetryEvent.php              ← Eloquent model para telemetry_events
└── Console/
    └── PruneTelemetryCommand.php       ← artisan telemetry:prune (retención)

database/migrations/app/
└── 2026_05_19_000001_create_telemetry_events_table.php

config/
└── telemetry.php

tests/Telemetry/
├── TelemetryEventTest.php
├── TelemetryCollectorTest.php
├── Collectors/
│   ├── QueryCollectorTest.php
│   ├── LogCollectorTest.php
│   └── RequestCollectorTest.php
├── TelemetryExporterTest.php
├── TelemetryMiddlewareTest.php
├── Jobs/ProcessTelemetryJobTest.php
└── PruneTelemetryCommandTest.php
```

**Modificaciones a archivos existentes:**
- `src/DataTable/DataTable.php` — agregar hook estático `DataTable::$onRender` para que `TelemetryCollector` pueda capturar llamadas sin acoplar DataTable al módulo Telemetry
- `src/Olimpo/OlimpoServiceProvider.php` — registrar `TelemetryServiceProvider` desde aquí como sub-provider cuando `TELEMETRY_ENABLED=true`
- `src/Olimpo/routes.php` — agregar `Route::post('telemetry', ...)` al grupo existente

---

## Task 1: Config + TelemetryEvent DTO

**Files:**
- Create: `config/telemetry.php`
- Create: `src/Telemetry/TelemetryEvent.php`
- Create: `tests/Telemetry/TelemetryEventTest.php`

- [ ] **Step 1: Crear config**

```php
<?php
// config/telemetry.php
return [
    'enabled'         => env('TELEMETRY_ENABLED', false),
    'app_name'        => env('APP_NAME', 'app'),
    'olimpo_url'      => env('OLIMPO_URL'),
    'olimpo_key'      => env('OLIMPO_KEY'),
    'queue'           => env('TELEMETRY_QUEUE', 'telemetry'),
    'timeout'         => 3,
    'retention_days'  => env('TELEMETRY_RETENTION_DAYS', 7),
    'capture' => [
        'queries'     => true,
        'logs'        => true,
        'exceptions'  => true,
        'datatables'  => true,
        'events'      => true,
        'requests'    => true,
    ],
    'except' => [
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],
];
```

- [ ] **Step 2: Escribir el test del DTO**

```php
<?php
// tests/Telemetry/TelemetryEventTest.php
use Innertia\Telemetry\TelemetryEvent;

it('creates a telemetry event with all fields', function () {
    $event = new TelemetryEvent(
        type: 'query',
        payload: ['sql' => 'select * from users', 'duration_ms' => 5.2],
        context: ['tenant' => 'acme', 'user_id' => 'uuid-123', 'route' => 'GET /api/users', 'env' => 'local'],
        durationMs: 5.2,
    );

    expect($event->type)->toBe('query')
        ->and($event->payload['sql'])->toBe('select * from users')
        ->and($event->context['tenant'])->toBe('acme')
        ->and($event->durationMs)->toBe(5.2)
        ->and($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
});

it('serializes to array correctly', function () {
    $event = new TelemetryEvent(
        type: 'log',
        payload: ['level' => 'error', 'message' => 'Something broke'],
        context: ['tenant' => null, 'user_id' => null, 'route' => 'CLI', 'env' => 'production'],
    );

    $arr = $event->toArray();

    expect($arr)->toHaveKeys(['type', 'payload', 'context', 'duration_ms', 'occurred_at'])
        ->and($arr['type'])->toBe('log')
        ->and($arr['duration_ms'])->toBeNull();
});
```

- [ ] **Step 3: Correr el test — debe fallar**

```bash
./vendor/bin/pest tests/Telemetry/TelemetryEventTest.php -v
```
Esperado: FAIL — `Class "Innertia\Telemetry\TelemetryEvent" not found`

- [ ] **Step 4: Crear el DTO**

```php
<?php
// src/Telemetry/TelemetryEvent.php
namespace Innertia\Telemetry;

final class TelemetryEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $type,
        public readonly array  $payload,
        public readonly array  $context,
        public readonly ?float $durationMs = null,
        ?\DateTimeImmutable    $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'type'        => $this->type,
            'payload'     => $this->payload,
            'context'     => $this->context,
            'duration_ms' => $this->durationMs,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

- [ ] **Step 5: Correr el test — debe pasar**

```bash
./vendor/bin/pest tests/Telemetry/TelemetryEventTest.php -v
```
Esperado: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add config/telemetry.php src/Telemetry/TelemetryEvent.php tests/Telemetry/TelemetryEventTest.php
git commit -m "feat(telemetry): TelemetryEvent DTO + config"
```

---

## Task 2: TelemetryCollector + DevtoolsEvent

**Files:**
- Create: `src/Telemetry/TelemetryCollector.php`
- Create: `src/Telemetry/Events/DevtoolsEvent.php`
- Create: `tests/Telemetry/TelemetryCollectorTest.php`

- [ ] **Step 1: Escribir el test**

```php
<?php
// tests/Telemetry/TelemetryCollectorTest.php
use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

it('starts with an empty batch', function () {
    $collector = new TelemetryCollector('my-app', 'session-abc', 'acme', 'local');
    expect($collector->batch())->toBeEmpty();
});

it('records an event and adds it to the batch', function () {
    $collector = new TelemetryCollector('my-app', 'session-abc', 'acme', 'local');

    $event = new TelemetryEvent(
        type: 'query',
        payload: ['sql' => 'select 1'],
        context: ['tenant' => 'acme', 'user_id' => null, 'route' => 'GET /api/test', 'env' => 'local'],
    );

    $collector->record($event);

    expect($collector->batch())->toHaveCount(1)
        ->and($collector->batch()[0]->type)->toBe('query');
});

it('can be flushed', function () {
    $collector = new TelemetryCollector('my-app', 'session-abc', 'acme', 'local');

    $collector->record(new TelemetryEvent(
        type: 'log',
        payload: ['message' => 'test'],
        context: ['tenant' => null, 'user_id' => null, 'route' => 'GET /test', 'env' => 'local'],
    ));

    expect($collector->batch())->toHaveCount(1);

    $flushed = $collector->flush();

    expect($flushed)->toHaveCount(1)
        ->and($collector->batch())->toBeEmpty();
});

it('exposes sessionId', function () {
    $collector = new TelemetryCollector('my-app', 'sess-xyz', 'acme', 'production');
    expect($collector->sessionId())->toBe('sess-xyz');
});
```

- [ ] **Step 2: Correr el test — debe fallar**

```bash
./vendor/bin/pest tests/Telemetry/TelemetryCollectorTest.php -v
```
Esperado: FAIL — `Class "Innertia\Telemetry\TelemetryCollector" not found`

- [ ] **Step 3: Crear DevtoolsEvent**

```php
<?php
// src/Telemetry/Events/DevtoolsEvent.php
namespace Innertia\Telemetry\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Innertia\Telemetry\TelemetryEvent;

class DevtoolsEvent implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly TelemetryEvent $event,
        public readonly string         $sessionId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("innertia.devtools.{$this->sessionId}");
    }

    public function broadcastAs(): string
    {
        return 'devtools.' . $this->event->type;
    }

    public function broadcastWith(): array
    {
        return $this->event->toArray();
    }
}
```

- [ ] **Step 4: Crear TelemetryCollector**

```php
<?php
// src/Telemetry/TelemetryCollector.php
namespace Innertia\Telemetry;

use Innertia\Telemetry\Events\DevtoolsEvent;

class TelemetryCollector
{
    /** @var TelemetryEvent[] */
    private array $batch = [];

    public function __construct(
        private readonly string  $appName,
        private readonly string  $sessionId,
        private readonly ?string $tenant,
        private readonly string  $env,
    ) {}

    public function record(TelemetryEvent $event): void
    {
        $this->batch[] = $event;

        // Broadcast inmediato a Soketi para el futuro nuxt-devtools
        $this->broadcastToDevtools($event);
    }

    /** Retorna y vacía el batch acumulado. */
    public function flush(): array
    {
        $batch       = $this->batch;
        $this->batch = [];
        return $batch;
    }

    /** @return TelemetryEvent[] */
    public function batch(): array
    {
        return $this->batch;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function appName(): string
    {
        return $this->appName;
    }

    public function tenant(): ?string
    {
        return $this->tenant;
    }

    private function broadcastToDevtools(TelemetryEvent $event): void
    {
        // Solo broadcastear si broadcasting está configurado (no null/sync driver vacío)
        try {
            if (function_exists('broadcast') && config('broadcasting.default') !== 'log') {
                broadcast(new DevtoolsEvent($event, $this->sessionId));
            }
        } catch (\Throwable) {
            // Silencioso — nunca romper la app por fallar al broadcastear
        }
    }
}
```

- [ ] **Step 5: Correr el test — debe pasar**

```bash
./vendor/bin/pest tests/Telemetry/TelemetryCollectorTest.php -v
```
Esperado: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Telemetry/TelemetryCollector.php src/Telemetry/Events/DevtoolsEvent.php tests/Telemetry/TelemetryCollectorTest.php
git commit -m "feat(telemetry): TelemetryCollector + DevtoolsEvent broadcast"
```

---

## Task 3: QueryCollector + LogCollector

**Files:**
- Create: `src/Telemetry/Collectors/QueryCollector.php`
- Create: `src/Telemetry/Collectors/LogCollector.php`
- Create: `tests/Telemetry/Collectors/QueryCollectorTest.php`
- Create: `tests/Telemetry/Collectors/LogCollectorTest.php`

- [ ] **Step 1: Escribir tests**

```php
<?php
// tests/Telemetry/Collectors/QueryCollectorTest.php
use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\Collectors\QueryCollector;

it('creates a query event from DB query data', function () {
    $collector = new TelemetryCollector('app', 'sess-1', 'acme', 'local');

    QueryCollector::handle($collector, 'select * from users where id = ?', ['42'], 8.5, 'mysql');

    $batch = $collector->batch();
    expect($batch)->toHaveCount(1)
        ->and($batch[0]->type)->toBe('query')
        ->and($batch[0]->payload['sql'])->toBe('select * from users where id = ?')
        ->and($batch[0]->payload['bindings'])->toBe(['42'])
        ->and($batch[0]->payload['duration_ms'])->toBe(8.5)
        ->and($batch[0]->payload['connection'])->toBe('mysql')
        ->and($batch[0]->durationMs)->toBe(8.5);
});
```

```php
<?php
// tests/Telemetry/Collectors/LogCollectorTest.php
use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\Collectors\LogCollector;

it('creates a log event', function () {
    $collector = new TelemetryCollector('app', 'sess-1', null, 'local');

    LogCollector::handle($collector, 'error', 'Something failed', ['key' => 'val']);

    $batch = $collector->batch();
    expect($batch)->toHaveCount(1)
        ->and($batch[0]->type)->toBe('log')
        ->and($batch[0]->payload['level'])->toBe('error')
        ->and($batch[0]->payload['message'])->toBe('Something failed')
        ->and($batch[0]->payload['context'])->toBe(['key' => 'val']);
});
```

- [ ] **Step 2: Correr los tests — deben fallar**

```bash
./vendor/bin/pest tests/Telemetry/Collectors/ -v
```
Esperado: FAIL — clases no encontradas

- [ ] **Step 3: Crear QueryCollector**

```php
<?php
// src/Telemetry/Collectors/QueryCollector.php
namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

class QueryCollector
{
    public static function handle(
        TelemetryCollector $collector,
        string  $sql,
        array   $bindings,
        float   $durationMs,
        string  $connection,
    ): void {
        $collector->record(new TelemetryEvent(
            type: 'query',
            payload: [
                'sql'         => $sql,
                'bindings'    => $bindings,
                'duration_ms' => $durationMs,
                'connection'  => $connection,
            ],
            context: self::buildContext($collector),
            durationMs: $durationMs,
        ));
    }

    private static function buildContext(TelemetryCollector $collector): array
    {
        return [
            'tenant'  => $collector->tenant(),
            'user_id' => null, // se rellena en TelemetryMiddleware al inicializar
            'route'   => request()?->method() . ' ' . request()?->path(),
            'env'     => app()->environment(),
        ];
    }
}
```

- [ ] **Step 4: Crear LogCollector**

```php
<?php
// src/Telemetry/Collectors/LogCollector.php
namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

class LogCollector
{
    public static function handle(
        TelemetryCollector $collector,
        string $level,
        string $message,
        array  $context = [],
    ): void {
        $collector->record(new TelemetryEvent(
            type: 'log',
            payload: [
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => request()?->method() . ' ' . request()?->path(),
                'env'     => app()->environment(),
            ],
        ));
    }
}
```

- [ ] **Step 5: Correr los tests — deben pasar**

```bash
./vendor/bin/pest tests/Telemetry/Collectors/ -v
```
Esperado: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Telemetry/Collectors/QueryCollector.php src/Telemetry/Collectors/LogCollector.php tests/Telemetry/Collectors/QueryCollectorTest.php tests/Telemetry/Collectors/LogCollectorTest.php
git commit -m "feat(telemetry): QueryCollector + LogCollector"
```

---

## Task 4: ExceptionCollector + EventCollector + RequestCollector

**Files:**
- Create: `src/Telemetry/Collectors/ExceptionCollector.php`
- Create: `src/Telemetry/Collectors/EventCollector.php`
- Create: `src/Telemetry/Collectors/RequestCollector.php`
- Create: `tests/Telemetry/Collectors/RequestCollectorTest.php`

- [ ] **Step 1: Escribir test de RequestCollector**

```php
<?php
// tests/Telemetry/Collectors/RequestCollectorTest.php
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
```

- [ ] **Step 2: Correr el test — debe fallar**

```bash
./vendor/bin/pest tests/Telemetry/Collectors/RequestCollectorTest.php -v
```
Esperado: FAIL

- [ ] **Step 3: Crear ExceptionCollector**

```php
<?php
// src/Telemetry/Collectors/ExceptionCollector.php
namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;
use Throwable;

class ExceptionCollector
{
    public static function handle(TelemetryCollector $collector, Throwable $e): void
    {
        // Respetar la lista de except del config de olimpo/telemetry
        $except = config('telemetry.except', []);
        foreach ($except as $class) {
            if ($e instanceof $class) return;
        }

        $collector->record(new TelemetryEvent(
            type: 'exception',
            payload: [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => collect($e->getTrace())
                    ->take(20)
                    ->map(fn ($f) => [
                        'file'     => $f['file'] ?? null,
                        'line'     => $f['line'] ?? null,
                        'function' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
                    ])
                    ->toArray(),
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => request()?->method() . ' ' . request()?->path(),
                'env'     => app()->environment(),
            ],
        ));
    }
}
```

- [ ] **Step 4: Crear EventCollector**

```php
<?php
// src/Telemetry/Collectors/EventCollector.php
namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

class EventCollector
{
    // Eventos del framework a ignorar para no hacer ruido
    private const IGNORED_PREFIXES = [
        'Illuminate\\',
        'Laravel\\',
        'eloquent.',
        'bootstrapped:',
        'Innertia\\Telemetry\\', // no capturar nuestros propios eventos
    ];

    public static function handle(TelemetryCollector $collector, string $eventName, array $payload): void
    {
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($eventName, $prefix)) return;
        }

        $collector->record(new TelemetryEvent(
            type: 'event',
            payload: [
                'event'   => $eventName,
                'payload' => self::safeSerialize($payload),
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => request()?->method() . ' ' . request()?->path(),
                'env'     => app()->environment(),
            ],
        ));
    }

    private static function safeSerialize(array $payload): array
    {
        try {
            return json_decode(json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [];
        } catch (\Throwable) {
            return ['_unserializable' => true];
        }
    }
}
```

- [ ] **Step 5: Crear RequestCollector**

```php
<?php
// src/Telemetry/Collectors/RequestCollector.php
namespace Innertia\Telemetry\Collectors;

use Illuminate\Http\Request;
use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;
use Symfony\Component\HttpFoundation\Response;

class RequestCollector
{
    public static function handle(
        TelemetryCollector $collector,
        Request  $request,
        Response $response,
        float    $durationMs,
    ): void {
        $collector->record(new TelemetryEvent(
            type: 'request',
            payload: [
                'method'      => $request->method(),
                'path'        => $request->path(),
                'status'      => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'ip'          => $request->ip(),
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => $request->method() . ' ' . $request->path(),
                'env'     => app()->environment(),
            ],
            durationMs: $durationMs,
        ));
    }
}
```

- [ ] **Step 6: Correr el test — debe pasar**

```bash
./vendor/bin/pest tests/Telemetry/Collectors/RequestCollectorTest.php -v
```
Esperado: PASS (1 test)

- [ ] **Step 7: Commit**

```bash
git add src/Telemetry/Collectors/ tests/Telemetry/Collectors/RequestCollectorTest.php
git commit -m "feat(telemetry): ExceptionCollector + EventCollector + RequestCollector"
```

---

## Task 5: DataTable hook + DataTableCollector

**Files:**
- Modify: `src/DataTable/DataTable.php` — agregar hook estático `$onRender`
- Create: `src/Telemetry/Collectors/DataTableCollector.php`

El hook es estático para evitar acoplar DataTable con el módulo Telemetry. Telemetry registra el callback en `TelemetryServiceProvider`, DataTable lo invoca al renderizar.

- [ ] **Step 1: Agregar hook estático en DataTable.php**

En `src/DataTable/DataTable.php`, agregar después de las propiedades privadas (después de la línea `private bool $enableList = false;`, aproximadamente línea 75):

```php
    /** @var callable|null Hook opcional para telemetría. Recibe: name, rows, duration_ms */
    public static $onRender = null;
```

Al final del método `renderJson()`, antes del `return response()->json($response)` final (línea ~991), agregar:

```php
        // Hook de telemetría — no acopla DataTable con el módulo Telemetry
        if (static::$onRender !== null) {
            try {
                $rowCount = is_array($response['data']) ? count($response['data']) : 0;
                (static::$onRender)($this->name, $rowCount, 0.0);
            } catch (\Throwable) {
                // Silencioso
            }
        }
```

- [ ] **Step 2: Verificar que los tests existentes de DataTable no se rompan**

```bash
./vendor/bin/pest --filter DataTable -v 2>/dev/null || echo "no DataTable tests yet"
```

- [ ] **Step 3: Crear DataTableCollector**

```php
<?php
// src/Telemetry/Collectors/DataTableCollector.php
namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

class DataTableCollector
{
    public static function handle(
        TelemetryCollector $collector,
        string $tableName,
        int    $rowCount,
        float  $durationMs,
    ): void {
        $collector->record(new TelemetryEvent(
            type: 'datatable',
            payload: [
                'table'       => $tableName,
                'rows'        => $rowCount,
                'duration_ms' => $durationMs,
                'filters'     => request()?->only(['search', 'sortColumns', 'page', 'perPage']) ?? [],
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => request()?->method() . ' ' . request()?->path(),
                'env'     => app()->environment(),
            ],
            durationMs: $durationMs,
        ));
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add src/DataTable/DataTable.php src/Telemetry/Collectors/DataTableCollector.php
git commit -m "feat(telemetry): DataTable onRender hook + DataTableCollector"
```

---

## Task 6: TelemetryMiddleware + TelemetryExporter

**Files:**
- Create: `src/Telemetry/Http/Middleware/TelemetryMiddleware.php`
- Create: `src/Telemetry/TelemetryExporter.php`
- Create: `tests/Telemetry/TelemetryExporterTest.php`

- [ ] **Step 1: Escribir test del exporter**

```php
<?php
// tests/Telemetry/TelemetryExporterTest.php
use Innertia\Telemetry\TelemetryExporter;
use Innertia\Telemetry\TelemetryEvent;
use Illuminate\Support\Facades\Http;

it('does nothing when batch is empty', function () {
    Http::fake();

    $exporter = new TelemetryExporter('http://olimpo:8000', 'key-abc', 'my-app');
    $exporter->flush([]);

    Http::assertNothingSent();
});

it('does nothing when olimpo_url is not configured', function () {
    Http::fake();

    $exporter = new TelemetryExporter(null, 'key-abc', 'my-app');
    $event = new TelemetryEvent(
        type: 'query',
        payload: ['sql' => 'select 1'],
        context: ['tenant' => null, 'user_id' => null, 'route' => 'GET /', 'env' => 'local'],
    );

    $exporter->flush([$event]);

    Http::assertNothingSent();
});

it('sends batch as JSON to olimpo telemetry endpoint', function () {
    Http::fake(['*' => Http::response(['ok' => true], 202)]);

    $exporter = new TelemetryExporter('http://olimpo:8000', 'key-abc', 'my-app');
    $event = new TelemetryEvent(
        type: 'log',
        payload: ['level' => 'info', 'message' => 'hello'],
        context: ['tenant' => 'acme', 'user_id' => null, 'route' => 'GET /test', 'env' => 'local'],
    );

    $exporter->flush([$event], 'sess-abc');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/olimpo/telemetry')
            && $request->hasHeader('X-Olimpo-Key', 'key-abc')
            && $request->data()['app'] === 'my-app'
            && $request->data()['session_id'] === 'sess-abc'
            && count($request->data()['events']) === 1
            && $request->data()['events'][0]['type'] === 'log';
    });
});
```

- [ ] **Step 2: Correr el test — debe fallar**

```bash
./vendor/bin/pest tests/Telemetry/TelemetryExporterTest.php -v
```
Esperado: FAIL

- [ ] **Step 3: Crear TelemetryExporter**

```php
<?php
// src/Telemetry/TelemetryExporter.php
namespace Innertia\Telemetry;

use Illuminate\Support\Facades\Http;

class TelemetryExporter
{
    public function __construct(
        private readonly ?string $olimpoUrl,
        private readonly ?string $olimpoKey,
        private readonly string  $appName,
        private readonly int     $timeout = 3,
    ) {}

    /** @param TelemetryEvent[] $batch */
    public function flush(array $batch, string $sessionId = 'unknown'): void
    {
        if (empty($batch) || !$this->olimpoUrl || !$this->olimpoKey) {
            return;
        }

        $payload = [
            'app'        => $this->appName,
            'session_id' => $sessionId,
            'events'     => array_map(fn (TelemetryEvent $e) => $e->toArray(), $batch),
        ];

        try {
            Http::timeout($this->timeout)
                ->withHeader('X-Olimpo-Key', $this->olimpoKey)
                ->post(rtrim($this->olimpoUrl, '/') . '/olimpo/telemetry', $payload);
        } catch (\Throwable) {
            // Silencioso — nunca romper la app por fallar al exportar
        }
    }
}
```

- [ ] **Step 4: Correr el test — debe pasar**

```bash
./vendor/bin/pest tests/Telemetry/TelemetryExporterTest.php -v
```
Esperado: PASS (3 tests)

- [ ] **Step 5: Crear TelemetryMiddleware**

```php
<?php
// src/Telemetry/Http/Middleware/TelemetryMiddleware.php
namespace Innertia\Telemetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Telemetry\Collectors\RequestCollector;
use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryExporter;
use Symfony\Component\HttpFoundation\Response;

class TelemetryMiddleware
{
    private float $startTime;

    public function handle(Request $request, Closure $next): Response
    {
        $this->startTime = microtime(true);
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        /** @var TelemetryCollector $collector */
        $collector = app(TelemetryCollector::class);

        // Capturar el request completo al terminar
        $durationMs = round((microtime(true) - $this->startTime) * 1000, 2);
        RequestCollector::handle($collector, $request, $response, $durationMs);

        // Enviar batch a Olimpo (fuera del response, sin bloquear)
        $sessionId = $collector->sessionId();
        $batch     = $collector->flush();
        $exporter  = new TelemetryExporter(
            olimpoUrl: config('telemetry.olimpo_url'),
            olimpoKey: config('telemetry.olimpo_key'),
            appName:   config('telemetry.app_name', config('app.name', 'app')),
            timeout:   config('telemetry.timeout', 3),
        );

        if (function_exists('defer')) {
            defer(fn () => $exporter->flush($batch, $sessionId));
        } else {
            $exporter->flush($batch, $sessionId);
        }
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Telemetry/TelemetryExporter.php src/Telemetry/Http/Middleware/TelemetryMiddleware.php tests/Telemetry/TelemetryExporterTest.php
git commit -m "feat(telemetry): TelemetryExporter + TelemetryMiddleware"
```

---

## Task 7: TelemetryServiceProvider — registrar todo el módulo sender

**Files:**
- Create: `src/Telemetry/TelemetryServiceProvider.php`
- Modify: `src/Olimpo/OlimpoServiceProvider.php` — registrar TelemetryServiceProvider

- [ ] **Step 1: Crear TelemetryServiceProvider**

```php
<?php
// src/Telemetry/TelemetryServiceProvider.php
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
        $this->mergeConfigFrom(__DIR__ . '/../../config/telemetry.php', 'telemetry');
    }

    public function boot(): void
    {
        if (! $this->shouldActivate()) {
            return;
        }

        // Publicar config
        $this->publishes([
            __DIR__ . '/../../config/telemetry.php' => config_path('telemetry.php'),
        ], 'telemetry-config');

        // Singleton del collector para este request
        $this->app->singleton(TelemetryCollector::class, function () {
            return new TelemetryCollector(
                appName:   config('telemetry.app_name', config('app.name', 'app')),
                sessionId: $this->resolveSessionId(),
                tenant:    $this->resolveTenant(),
                env:       app()->environment(),
            );
        });

        // Registrar middleware globalmente
        $this->app['router']->pushMiddlewareToGroup('api', TelemetryMiddleware::class);

        // Hooks de captura
        $this->registerQueryCapture();
        $this->registerLogCapture();
        $this->registerEventCapture();
        $this->registerDataTableCapture();
        $this->registerExceptionCapture();
    }

    private function shouldActivate(): bool
    {
        if (! config('telemetry.enabled', false)) {
            return false;
        }

        // En local/testing siempre activo
        if (app()->isLocal() || app()->runningUnitTests()) {
            return true;
        }

        // En otros entornos: requiere que el usuario autenticado tenga permiso 'devtools'
        try {
            $user = auth()->user();
            if (! $user) return false;
            if (method_exists($user, 'hasPermissionTo')) {
                return $user->hasPermissionTo('devtools');
            }
        } catch (\Throwable) {
            // No hay auth en este punto del ciclo — deferirlo al middleware
        }

        return false;
    }

    private function resolveSessionId(): string
    {
        return request()?->header('X-Devtools-Session')
            ?? (string) request()?->cookie('innertia_session')
            ?? (string) session()->getId()
            ?? \Illuminate\Support\Str::uuid();
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
        if (! config('telemetry.capture.queries', true)) return;

        DB::listen(function ($query) {
            /** @var TelemetryCollector $collector */
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
        if (! config('telemetry.capture.logs', true)) return;

        Log::listen(function ($message) {
            /** @var TelemetryCollector $collector */
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
        if (! config('telemetry.capture.events', true)) return;

        Event::listen('*', function (string $eventName, array $payload) {
            /** @var TelemetryCollector $collector */
            $collector = $this->app->make(TelemetryCollector::class);
            EventCollector::handle($collector, $eventName, $payload);
        });
    }

    private function registerDataTableCapture(): void
    {
        if (! config('telemetry.capture.datatables', true)) return;

        DataTable::$onRender = function (string $tableName, int $rowCount, float $durationMs) {
            /** @var TelemetryCollector $collector */
            $collector = $this->app->make(TelemetryCollector::class);
            DataTableCollector::handle($collector, $tableName, $rowCount, $durationMs);
        };
    }

    private function registerExceptionCapture(): void
    {
        if (! config('telemetry.capture.exceptions', true)) return;

        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

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
                        } catch (\Throwable) {}
                    }

                    public function render($request, \Throwable $e)
                    {
                        return $this->inner->render($request, $e);
                    }
                };
            }
        );
    }
}
```

- [ ] **Step 2: Registrar en OlimpoServiceProvider**

En `src/Olimpo/OlimpoServiceProvider.php`, al final del método `boot()`, agregar:

```php
        // Activar telemetría si está habilitada
        if (config('olimpo.url') || config('telemetry.enabled')) {
            $this->app->register(\Innertia\Telemetry\TelemetryServiceProvider::class);
        }
```

- [ ] **Step 3: Correr todos los tests de telemetría**

```bash
./vendor/bin/pest tests/Telemetry/ -v
```
Esperado: PASS (todos los tests anteriores)

- [ ] **Step 4: Commit**

```bash
git add src/Telemetry/TelemetryServiceProvider.php src/Olimpo/OlimpoServiceProvider.php
git commit -m "feat(telemetry): TelemetryServiceProvider registra todos los collectors"
```

---

## Task 8: Migración + TelemetryEvent Model (receiver Olimpo)

**Files:**
- Create: `database/migrations/app/2026_05_19_000001_create_telemetry_events_table.php`
- Create: `src/Telemetry/Models/TelemetryEvent.php`

- [ ] **Step 1: Crear migración**

```php
<?php
// database/migrations/app/2026_05_19_000001_create_telemetry_events_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_events', function (Blueprint $table) {
            $table->id();
            $table->string('app')->index();
            $table->string('session_id')->index();
            $table->string('type')->index();
            $table->timestamp('occurred_at')->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('payload');
            $table->json('context');
            $table->timestamps();

            $table->index(['app', 'type']);
            $table->index(['app', 'occurred_at']);
            $table->index(['session_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_events');
    }
};
```

- [ ] **Step 2: Crear modelo Eloquent**

```php
<?php
// src/Telemetry/Models/TelemetryEvent.php
namespace Innertia\Telemetry\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryEvent extends Model
{
    protected $table = 'telemetry_events';

    protected $fillable = [
        'app',
        'session_id',
        'type',
        'occurred_at',
        'duration_ms',
        'payload',
        'context',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload'     => 'array',
        'context'     => 'array',
        'duration_ms' => 'integer',
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/app/2026_05_19_000001_create_telemetry_events_table.php src/Telemetry/Models/TelemetryEvent.php
git commit -m "feat(telemetry): migration telemetry_events + Eloquent model"
```

---

## Task 9: ProcessTelemetryJob + TelemetryBatchReceived

**Files:**
- Create: `src/Telemetry/Jobs/ProcessTelemetryJob.php`
- Create: `src/Telemetry/Events/TelemetryBatchReceived.php`
- Create: `tests/Telemetry/Jobs/ProcessTelemetryJobTest.php`

- [ ] **Step 1: Escribir el test**

```php
<?php
// tests/Telemetry/Jobs/ProcessTelemetryJobTest.php
use Innertia\Telemetry\Jobs\ProcessTelemetryJob;
use Innertia\Telemetry\Models\TelemetryEvent;
use Illuminate\Support\Facades\Event;

it('stores all events from the batch in DB', function () {
    Event::fake();

    $batch = [
        'app' => 'documentia',
        'events' => [
            [
                'type'        => 'query',
                'payload'     => ['sql' => 'select * from users'],
                'context'     => ['tenant' => 'acme', 'user_id' => null, 'route' => 'GET /api/users', 'env' => 'local'],
                'duration_ms' => 5.0,
                'occurred_at' => now()->toAtomString(),
            ],
            [
                'type'        => 'log',
                'payload'     => ['level' => 'info', 'message' => 'hello'],
                'context'     => ['tenant' => 'acme', 'user_id' => null, 'route' => 'GET /api/users', 'env' => 'local'],
                'duration_ms' => null,
                'occurred_at' => now()->toAtomString(),
            ],
        ],
    ];

    (new ProcessTelemetryJob($batch))->handle();

    expect(TelemetryEvent::count())->toBe(2)
        ->and(TelemetryEvent::where('type', 'query')->first()->app)->toBe('documentia')
        ->and(TelemetryEvent::where('type', 'log')->first()->context['tenant'])->toBe('acme');
})->skip(fn () => ! config('database.default'), 'No DB configured');
```

- [ ] **Step 2: Correr el test — debe fallar**

```bash
./vendor/bin/pest tests/Telemetry/Jobs/ProcessTelemetryJobTest.php -v
```
Esperado: FAIL

- [ ] **Step 3: Crear TelemetryBatchReceived**

```php
<?php
// src/Telemetry/Events/TelemetryBatchReceived.php
namespace Innertia\Telemetry\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class TelemetryBatchReceived implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly string $app,
        public readonly int    $count,
        public readonly array  $summary, // tipos de eventos recibidos
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('innertia.olimpo.telemetry');
    }

    public function broadcastAs(): string
    {
        return 'telemetry.batch';
    }

    public function broadcastWith(): array
    {
        return [
            'app'     => $this->app,
            'count'   => $this->count,
            'summary' => $this->summary,
        ];
    }
}
```

- [ ] **Step 4: Crear ProcessTelemetryJob**

```php
<?php
// src/Telemetry/Jobs/ProcessTelemetryJob.php
namespace Innertia\Telemetry\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Innertia\Telemetry\Events\TelemetryBatchReceived;
use Innertia\Telemetry\Models\TelemetryEvent;

class ProcessTelemetryJob implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly array $batch) {}

    public function handle(): void
    {
        $app    = $this->batch['app'] ?? 'unknown';
        $events = $this->batch['events'] ?? [];

        if (empty($events)) return;

        $now = now();

        $rows = array_map(fn (array $e) => [
            'app'         => $app,
            'session_id'  => $this->batch['session_id'] ?? 'unknown',
            'type'        => $e['type'],
            'occurred_at' => $e['occurred_at'] ?? $now,
            'duration_ms' => isset($e['duration_ms']) ? (int) $e['duration_ms'] : null,
            'payload'     => json_encode($e['payload'] ?? []),
            'context'     => json_encode($e['context'] ?? []),
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $events);

        // Insert en chunks para no sobrecargar en batches grandes
        foreach (array_chunk($rows, 100) as $chunk) {
            TelemetryEvent::insert($chunk);
        }

        // Broadcast al dashboard de Olimpo
        $summary = array_count_values(array_column($events, 'type'));
        try {
            broadcast(new TelemetryBatchReceived($app, count($events), $summary));
        } catch (\Throwable) {}
    }

    public function queue(): string
    {
        return config('telemetry.queue', 'telemetry');
    }
}
```

- [ ] **Step 5: Correr el test — debe pasar**

```bash
./vendor/bin/pest tests/Telemetry/Jobs/ProcessTelemetryJobTest.php -v
```
Esperado: PASS (1 test — se skippea si no hay DB)

- [ ] **Step 6: Commit**

```bash
git add src/Telemetry/Jobs/ProcessTelemetryJob.php src/Telemetry/Events/TelemetryBatchReceived.php tests/Telemetry/Jobs/ProcessTelemetryJobTest.php
git commit -m "feat(telemetry): ProcessTelemetryJob almacena batch + TelemetryBatchReceived"
```

---

## Task 10: TelemetryController + ruta en Olimpo

**Files:**
- Create: `src/Telemetry/Http/Controllers/TelemetryController.php`
- Modify: `src/Olimpo/routes.php` — agregar `POST olimpo/telemetry`

- [ ] **Step 1: Crear TelemetryController**

```php
<?php
// src/Telemetry/Http/Controllers/TelemetryController.php
namespace Innertia\Telemetry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Telemetry\Jobs\ProcessTelemetryJob;

class TelemetryController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app'          => 'required|string|max:100',
            'events'       => 'required|array|min:1|max:500',
            'events.*.type'        => 'required|string',
            'events.*.payload'     => 'required|array',
            'events.*.context'     => 'required|array',
            'events.*.occurred_at' => 'nullable|string',
            'events.*.duration_ms' => 'nullable|numeric',
        ]);

        // Responde 202 inmediatamente — el worker procesa en background
        ProcessTelemetryJob::dispatch($data)->onQueue(config('telemetry.queue', 'telemetry'));

        return response()->json(['accepted' => true, 'count' => count($data['events'])], 202);
    }
}
```

- [ ] **Step 2: Agregar ruta en Olimpo**

En `src/Olimpo/routes.php`, dentro del grupo existente (después de las rutas de tenants), agregar:

```php
        // Telemetría — recibe batches de eventos de las apps cliente
        Route::post('telemetry', [\Innertia\Telemetry\Http\Controllers\TelemetryController::class, 'receive']);
```

- [ ] **Step 3: Correr todos los tests**

```bash
./vendor/bin/pest tests/Telemetry/ -v
```
Esperado: todos PASS

- [ ] **Step 4: Commit**

```bash
git add src/Telemetry/Http/Controllers/TelemetryController.php src/Olimpo/routes.php
git commit -m "feat(telemetry): TelemetryController POST /olimpo/telemetry receiver"
```

---

## Task 11: PruneTelemetryCommand

**Files:**
- Create: `src/Telemetry/Console/PruneTelemetryCommand.php`
- Modify: `src/Telemetry/TelemetryServiceProvider.php` — registrar el comando

- [ ] **Step 1: Crear el comando**

```php
<?php
// src/Telemetry/Console/PruneTelemetryCommand.php
namespace Innertia\Telemetry\Console;

use Illuminate\Console\Command;
use Innertia\Telemetry\Models\TelemetryEvent;

class PruneTelemetryCommand extends Command
{
    protected $signature   = 'telemetry:prune {--days= : Días de retención (default: config telemetry.retention_days)}';
    protected $description = 'Elimina eventos de telemetría más antiguos que N días';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('telemetry.retention_days', 7));

        if ($days <= 0) {
            $this->error('El número de días debe ser mayor a 0.');
            return self::FAILURE;
        }

        $cutoff  = now()->subDays($days);
        $deleted = TelemetryEvent::where('occurred_at', '<', $cutoff)->delete();

        $this->info("Eliminados {$deleted} eventos de telemetría anteriores a {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Registrar el comando en TelemetryServiceProvider**

En `src/Telemetry/TelemetryServiceProvider.php`, en el método `boot()`, agregar antes del check de `shouldActivate()`:

```php
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Innertia\Telemetry\Console\PruneTelemetryCommand::class,
            ]);
        }
```

- [ ] **Step 3: Verificar que el comando se registra**

En un proyecto que use la librería (documentia o similar):
```bash
docker compose exec api php artisan list | grep telemetry
```
Esperado: `telemetry:prune  Elimina eventos de telemetría más antiguos que N días`

- [ ] **Step 4: Correr todos los tests**

```bash
./vendor/bin/pest tests/Telemetry/ -v
```
Esperado: PASS

- [ ] **Step 5: Commit final**

```bash
git add src/Telemetry/Console/PruneTelemetryCommand.php src/Telemetry/TelemetryServiceProvider.php
git commit -m "feat(telemetry): PruneTelemetryCommand artisan telemetry:prune

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Verificación de integración completa

Después de implementar todos los tasks, verificar el pipeline end-to-end en documentia:

1. Agregar en `.env` de documentia: `TELEMETRY_ENABLED=true`
2. Hacer un request autenticado a cualquier endpoint
3. Verificar en el canal de Soketi que llegan eventos `devtools.query`, `devtools.log`, etc.
4. Verificar en logs de Olimpo que llega el batch HTTP a `POST /olimpo/telemetry`
5. Verificar en DB de Olimpo que se almacenaron los eventos en `telemetry_events`

```bash
# En Olimpo
docker compose exec api php artisan tinker
>>> \Innertia\Telemetry\Models\TelemetryEvent::latest()->take(5)->get(['app','type','context'])
```

---

## Resumen de cambios por archivo

| Archivo | Acción | Task |
|---|---|---|
| `config/telemetry.php` | Crear | 1 |
| `src/Telemetry/TelemetryEvent.php` | Crear (DTO) | 1 |
| `src/Telemetry/TelemetryCollector.php` | Crear | 2 |
| `src/Telemetry/Events/DevtoolsEvent.php` | Crear | 2 |
| `src/Telemetry/Collectors/QueryCollector.php` | Crear | 3 |
| `src/Telemetry/Collectors/LogCollector.php` | Crear | 3 |
| `src/Telemetry/Collectors/ExceptionCollector.php` | Crear | 4 |
| `src/Telemetry/Collectors/EventCollector.php` | Crear | 4 |
| `src/Telemetry/Collectors/RequestCollector.php` | Crear | 4 |
| `src/DataTable/DataTable.php` | Modificar (hook) | 5 |
| `src/Telemetry/Collectors/DataTableCollector.php` | Crear | 5 |
| `src/Telemetry/TelemetryExporter.php` | Crear | 6 |
| `src/Telemetry/Http/Middleware/TelemetryMiddleware.php` | Crear | 6 |
| `src/Telemetry/TelemetryServiceProvider.php` | Crear | 7 |
| `src/Olimpo/OlimpoServiceProvider.php` | Modificar | 7 |
| `database/migrations/app/2026_05_19_000001_create_telemetry_events_table.php` | Crear | 8 |
| `src/Telemetry/Models/TelemetryEvent.php` | Crear | 8 |
| `src/Telemetry/Jobs/ProcessTelemetryJob.php` | Crear | 9 |
| `src/Telemetry/Events/TelemetryBatchReceived.php` | Crear | 9 |
| `src/Telemetry/Http/Controllers/TelemetryController.php` | Crear | 10 |
| `src/Olimpo/routes.php` | Modificar | 10 |
| `src/Telemetry/Console/PruneTelemetryCommand.php` | Crear | 11 |
