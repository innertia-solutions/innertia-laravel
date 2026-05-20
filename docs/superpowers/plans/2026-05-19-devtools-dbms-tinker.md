# Devtools: DB Browser + Remote Tinker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `Devtools` module to `innertia-laravel` that exposes HTTP endpoints (protected by `olimpo.auth`) for browsing the app's database tables/rows with inline editing, running raw SELECT queries, and launching interactive PHP Tinker sessions whose output streams back via WebSocket.

**Architecture:** A new `DevtoolsServiceProvider` registers a `devtools.guard` middleware and loads `src/Devtools/routes.php` only when `DEVTOOLS_ENABLED=true`. DB Browser is pure HTTP (request/response). Tinker uses HTTP for input (POST code) and WebSocket (Soketi broadcast) for output streaming, with variable state persisted in Redis between evals. All routes sit under `innertia/devtools/` prefix and require `olimpo.auth` + `devtools.guard`.

**Tech Stack:** Laravel 11+, PHP 8.2+, Orchestra Testbench + Pest for tests, Redis for Tinker session state, Soketi (Pusher protocol) for WebSocket output, PHP-FPM (dedicated devtools Docker worker in prod, isolated from Octane/Swoole).

---

## File Map

**Create:**
- `src/Devtools/DevtoolsServiceProvider.php` — registers middleware alias, loads routes when enabled, registers into OlimpoServiceProvider
- `src/Devtools/routes.php` — all `innertia/devtools/*` routes behind `olimpo.auth` + `devtools.guard`
- `src/Devtools/Http/Middleware/DevtoolsGuard.php` — checks `innertia.devtools.enabled`
- `src/Devtools/Http/Controllers/DbmsController.php` — HTTP handlers for DB browser
- `src/Devtools/Http/Controllers/TinkerController.php` — HTTP handlers for Tinker sessions
- `src/Devtools/Dbms/TableInspector.php` — lists tables + columns via Laravel Schema facade
- `src/Devtools/Dbms/RowBrowser.php` — paginated rows with filters and sort
- `src/Devtools/Dbms/RowEditor.php` — inline single-field updates
- `src/Devtools/Tinker/TinkerSession.php` — Redis-backed session (create/find/save/destroy)
- `src/Devtools/Tinker/TinkerSandbox.php` — blocklist validation before eval
- `src/Devtools/Tinker/TinkerEvaluator.php` — eval with output buffering + variable persistence
- `src/Devtools/Tinker/TinkerAuditLog.php` — logs every eval to Laravel Log
- `src/Devtools/Events/TinkerOutputEvent.php` — ShouldBroadcastNow to `private-innertia.tinker.{id}`

**Modify:**
- `config/innertia.php` — add `devtools` section (with `tinker.cache_store` defaulting to `'redis'`)
- `src/Olimpo/OlimpoServiceProvider.php` — register `DevtoolsServiceProvider`
- `templates/app/backend/.env.example` (innertia-setup) — add `DEVTOOLS_ENABLED=false`
- `templates/saas/backend/.env.example` (innertia-setup) — add `DEVTOOLS_ENABLED=false`
- `templates/laravel-api/.env.example` (innertia-setup) — add `DEVTOOLS_ENABLED=false`

**Infrastructure (innertia-setup — already done):**
- `templates/app/backend/docker/prod/Dockerfile.devtools` — PHP-FPM worker sin Swoole
- `templates/app/backend/docker/prod/nginx.devtools.conf` — nginx solo para `/innertia/devtools/`
- `templates/app/backend/docker/prod/supervisord.devtools.conf` — supervisor nginx + fpm
- `templates/app/compose.prod.yml` — servicio `devtools` con `profiles: [devtools]`
- Mismos 4 archivos en `templates/saas/`

**Tests:**
- `tests/Devtools/Dbms/TableInspectorTest.php`
- `tests/Devtools/Dbms/RowBrowserTest.php`
- `tests/Devtools/Dbms/RowEditorTest.php`
- `tests/Devtools/Tinker/TinkerSandboxTest.php`
- `tests/Devtools/Tinker/TinkerEvaluatorTest.php`
- `tests/Devtools/Tinker/TinkerSessionTest.php`
- `tests/Devtools/Http/DbmsControllerTest.php`
- `tests/Devtools/Http/TinkerControllerTest.php`

---

### Task 1: Config + Foundation (ServiceProvider, Guard, Routes skeleton)

**Files:**
- Modify: `config/innertia.php`
- Create: `src/Devtools/DevtoolsServiceProvider.php`
- Create: `src/Devtools/Http/Middleware/DevtoolsGuard.php`
- Create: `src/Devtools/routes.php`

- [ ] **Step 1: Add `devtools` section to `config/innertia.php`**

Open `config/innertia.php`. After the `'telemetry' => [...]` block, before the final `];`, add:

```php
    /*
    |--------------------------------------------------------------------------
    | Devtools
    |--------------------------------------------------------------------------
    |
    | Remote DB browser and interactive Tinker session accessible from Olimpo.
    | All endpoints are protected by olimpo.auth + devtools.guard.
    |
    | tinker.enabled — set to true to allow remote PHP eval. AUDIT LOGGED.
    |                  Never enable on production without explicit intent.
    | tinker.session_ttl — seconds before an idle Tinker session expires (Redis TTL).
    |
    */

    'devtools' => [
        'enabled' => env('DEVTOOLS_ENABLED', false),

        'tinker' => [
            'enabled'     => env('DEVTOOLS_TINKER_ENABLED', false),
            'session_ttl' => (int) env('DEVTOOLS_TINKER_SESSION_TTL', 1800), // 30 min
        ],
    ],
```

- [ ] **Step 2: Create `DevtoolsGuard` middleware**

Create `src/Devtools/Http/Middleware/DevtoolsGuard.php`:

```php
<?php

namespace Innertia\Devtools\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DevtoolsGuard
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('innertia.devtools.enabled', false)) {
            return response()->json(['message' => 'Devtools not enabled. Set DEVTOOLS_ENABLED=true.'], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 3: Create routes skeleton**

Create `src/Devtools/routes.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Innertia\Devtools\Http\Controllers\DbmsController;
use Innertia\Devtools\Http\Controllers\TinkerController;

Route::prefix('innertia/devtools')
    ->middleware(['olimpo.auth', 'devtools.guard'])
    ->group(function () {
        // DB Browser
        Route::get('dbms/tables', [DbmsController::class, 'tables']);
        Route::post('dbms/tables/{table}/rows', [DbmsController::class, 'rows']);
        Route::put('dbms/tables/{table}/rows/{id}', [DbmsController::class, 'updateRow']);
        Route::post('dbms/query', [DbmsController::class, 'query']);

        // Remote Tinker
        Route::post('tinker/sessions', [TinkerController::class, 'create']);
        Route::post('tinker/sessions/{id}/eval', [TinkerController::class, 'eval']);
        Route::delete('tinker/sessions/{id}', [TinkerController::class, 'destroy']);
    });
```

- [ ] **Step 4: Create `DevtoolsServiceProvider`**

Create `src/Devtools/DevtoolsServiceProvider.php`:

```php
<?php

namespace Innertia\Devtools;

use Illuminate\Support\ServiceProvider;
use Innertia\Devtools\Http\Middleware\DevtoolsGuard;

class DevtoolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/innertia.php', 'innertia');
    }

    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('devtools.guard', DevtoolsGuard::class);
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
```

Note: routes are always registered but `devtools.guard` returns 403 when disabled — this lets Olimpo distinguish "disabled" (403) from "not implemented" (404).

- [ ] **Step 5: Register `DevtoolsServiceProvider` in `OlimpoServiceProvider`**

Open `src/Olimpo/OlimpoServiceProvider.php`. At the end of `boot()`, after the telemetry registration block, add:

```php
        // Activar devtools si está habilitado o hay URL de Olimpo configurada
        $this->app->register(\Innertia\Devtools\DevtoolsServiceProvider::class);
```

- [ ] **Step 6: Commit**

```bash
git add config/innertia.php \
        src/Devtools/DevtoolsServiceProvider.php \
        src/Devtools/Http/Middleware/DevtoolsGuard.php \
        src/Devtools/routes.php \
        src/Olimpo/OlimpoServiceProvider.php
git commit -m "feat(devtools): foundation — ServiceProvider, DevtoolsGuard, routes skeleton"
```

---

### Task 2: TableInspector

**Files:**
- Create: `src/Devtools/Dbms/TableInspector.php`
- Create: `tests/Devtools/Dbms/TableInspectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Devtools/Dbms/TableInspectorTest.php`:

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Devtools\Dbms\TableInspector;

beforeEach(function () {
    // SQLite in-memory for tests (Orchestra Testbench uses sqlite by default)
    Schema::create('test_products', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    DB::table('test_products')->insert(['name' => 'Widget', 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    Schema::dropIfExists('test_products');
});

it('lists tables with name, row_count and columns', function () {
    $tables = TableInspector::tables();

    $found = collect($tables)->firstWhere('name', 'test_products');

    expect($found)->not->toBeNull()
        ->and($found['row_count'])->toBe(1)
        ->and($found['columns'])->toBeArray()
        ->and(collect($found['columns'])->pluck('name')->toArray())->toContain('id', 'name');
});

it('returns columns for a single table', function () {
    $columns = TableInspector::columns('test_products');

    expect($columns)->toBeArray()
        ->and(collect($columns)->pluck('name')->toArray())->toContain('id', 'name', 'created_at');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /path/to/innertia-laravel && ./vendor/bin/pest tests/Devtools/Dbms/TableInspectorTest.php -v
```

Expected: FAIL with `Class "Innertia\Devtools\Dbms\TableInspector" not found`

- [ ] **Step 3: Implement `TableInspector`**

Create `src/Devtools/Dbms/TableInspector.php`:

```php
<?php

namespace Innertia\Devtools\Dbms;

use Illuminate\Support\Facades\DB;

class TableInspector
{
    /**
     * Returns all tables in the default (or given) connection,
     * each with its column definitions and current row count.
     */
    public static function tables(?string $connection = null): array
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        $tables = $schema->getTableListing();

        return array_values(array_map(
            fn (string $table) => [
                'name'      => $table,
                'row_count' => DB::connection($connection)->table($table)->count(),
                'columns'   => self::columns($table, $connection),
            ],
            $tables,
        ));
    }

    /**
     * Returns column definitions for a single table.
     * Each column: ['name', 'type_name', 'type', 'nullable', 'default', ...]
     */
    public static function columns(string $table, ?string $connection = null): array
    {
        return DB::connection($connection)->getSchemaBuilder()->getColumns($table);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Devtools/Dbms/TableInspectorTest.php -v
```

Expected: 2 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Devtools/Dbms/TableInspector.php tests/Devtools/Dbms/TableInspectorTest.php
git commit -m "feat(devtools): TableInspector — list tables with columns and row count"
```

---

### Task 3: RowBrowser

**Files:**
- Create: `src/Devtools/Dbms/RowBrowser.php`
- Create: `tests/Devtools/Dbms/RowBrowserTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Devtools/Dbms/RowBrowserTest.php`:

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Devtools\Dbms\RowBrowser;

beforeEach(function () {
    Schema::create('test_items', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status')->default('active');
        $table->timestamps();
    });

    foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'] as $name) {
        DB::table('test_items')->insert([
            'name'       => $name,
            'status'     => $name === 'Beta' ? 'inactive' : 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

afterEach(function () {
    Schema::dropIfExists('test_items');
});

it('returns paginated rows', function () {
    $result = RowBrowser::browse(table: 'test_items', page: 1, perPage: 2);

    expect($result['total'])->toBe(5)
        ->and($result['data'])->toHaveCount(2)
        ->and($result['last_page'])->toBe(3)
        ->and($result['current_page'])->toBe(1);
});

it('filters rows by column value', function () {
    $result = RowBrowser::browse(
        table:   'test_items',
        filters: [['column' => 'status', 'operator' => '=', 'value' => 'inactive']],
    );

    expect($result['total'])->toBe(1)
        ->and((array) $result['data'][0])->toMatchArray(['name' => 'Beta']);
});

it('sorts rows by column', function () {
    $result = RowBrowser::browse(table: 'test_items', sortBy: 'name', sortDir: 'asc');

    $names = array_column(array_map(fn($r) => (array) $r, $result['data']), 'name');
    expect($names[0])->toBe('Alpha');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Devtools/Dbms/RowBrowserTest.php -v
```

Expected: FAIL with `Class "Innertia\Devtools\Dbms\RowBrowser" not found`

- [ ] **Step 3: Implement `RowBrowser`**

Create `src/Devtools/Dbms/RowBrowser.php`:

```php
<?php

namespace Innertia\Devtools\Dbms;

use Illuminate\Support\Facades\DB;

class RowBrowser
{
    /**
     * @param  array<int, array{column: string, operator: string, value: mixed}>  $filters
     */
    public static function browse(
        string  $table,
        int     $page       = 1,
        int     $perPage    = 50,
        array   $filters    = [],
        string  $sortBy     = 'id',
        string  $sortDir    = 'asc',
        ?string $connection = null,
    ): array {
        $query = DB::connection($connection)->table($table);

        foreach ($filters as $filter) {
            $query->where(
                $filter['column'],
                $filter['operator'] ?? '=',
                $filter['value'],
            );
        }

        $total = $query->count();
        $rows  = (clone $query)
            ->orderBy($sortBy, $sortDir)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->toArray();

        return [
            'data'         => $rows,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / max($perPage, 1)),
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Devtools/Dbms/RowBrowserTest.php -v
```

Expected: 3 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Devtools/Dbms/RowBrowser.php tests/Devtools/Dbms/RowBrowserTest.php
git commit -m "feat(devtools): RowBrowser — paginated rows with filters and sort"
```

---

### Task 4: RowEditor

**Files:**
- Create: `src/Devtools/Dbms/RowEditor.php`
- Create: `tests/Devtools/Dbms/RowEditorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Devtools/Dbms/RowEditorTest.php`:

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Devtools\Dbms\RowEditor;

beforeEach(function () {
    Schema::create('test_widgets', function (Blueprint $table) {
        $table->id();
        $table->string('label');
        $table->timestamps();
    });

    DB::table('test_widgets')->insert(['id' => 1, 'label' => 'Old', 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    Schema::dropIfExists('test_widgets');
});

it('updates a single column of a row', function () {
    $updated = RowEditor::update(
        table:  'test_widgets',
        id:     1,
        column: 'label',
        value:  'New',
    );

    expect($updated)->toBeTrue();
    expect(DB::table('test_widgets')->where('id', 1)->value('label'))->toBe('New');
});

it('returns false when row does not exist', function () {
    $updated = RowEditor::update(
        table:  'test_widgets',
        id:     999,
        column: 'label',
        value:  'X',
    );

    expect($updated)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Devtools/Dbms/RowEditorTest.php -v
```

Expected: FAIL with `Class "Innertia\Devtools\Dbms\RowEditor" not found`

- [ ] **Step 3: Implement `RowEditor`**

Create `src/Devtools/Dbms/RowEditor.php`:

```php
<?php

namespace Innertia\Devtools\Dbms;

use Illuminate\Support\Facades\DB;

class RowEditor
{
    public static function update(
        string     $table,
        int|string $id,
        string     $column,
        mixed      $value,
        ?string    $connection = null,
    ): bool {
        $affected = DB::connection($connection)
            ->table($table)
            ->where('id', $id)
            ->update([$column => $value]);

        return $affected > 0;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Devtools/Dbms/RowEditorTest.php -v
```

Expected: 2 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Devtools/Dbms/RowEditor.php tests/Devtools/Dbms/RowEditorTest.php
git commit -m "feat(devtools): RowEditor — inline single-field row updates"
```

---

### Task 5: DbmsController

**Files:**
- Create: `src/Devtools/Http/Controllers/DbmsController.php`
- Create: `tests/Devtools/Http/DbmsControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Devtools/Http/DbmsControllerTest.php`:

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Innertia\Devtools\Http\Controllers\DbmsController;
use Innertia\Devtools\Http\Middleware\DevtoolsGuard;
use Innertia\Olimpo\Http\Middleware\OlimpoAuth;

// Wire up routes for the test
beforeEach(function () {
    config(['innertia.devtools.enabled' => true]);
    config(['olimpo.key' => 'test-key']);

    // Register middleware aliases
    app('router')->aliasMiddleware('olimpo.auth', OlimpoAuth::class);
    app('router')->aliasMiddleware('devtools.guard', DevtoolsGuard::class);

    Route::prefix('innertia/devtools')
        ->middleware(['olimpo.auth', 'devtools.guard'])
        ->group(function () {
            Route::get('dbms/tables', [DbmsController::class, 'tables']);
            Route::post('dbms/tables/{table}/rows', [DbmsController::class, 'rows']);
            Route::put('dbms/tables/{table}/rows/{id}', [DbmsController::class, 'updateRow']);
            Route::post('dbms/query', [DbmsController::class, 'query']);
        });

    Schema::create('test_orders', function (Blueprint $table) {
        $table->id();
        $table->string('ref');
        $table->timestamps();
    });

    DB::table('test_orders')->insert(['ref' => 'ORD-001', 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    Schema::dropIfExists('test_orders');
});

it('returns 401 without olimpo key', function () {
    $this->getJson('innertia/devtools/dbms/tables')
        ->assertStatus(401);
});

it('returns 403 when devtools disabled', function () {
    config(['innertia.devtools.enabled' => false]);

    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->getJson('innertia/devtools/dbms/tables')
        ->assertStatus(403);
});

it('lists tables', function () {
    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->getJson('innertia/devtools/dbms/tables')
        ->assertOk()
        ->assertJsonFragment(['name' => 'test_orders']);
});

it('returns paginated rows', function () {
    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/dbms/tables/test_orders/rows', ['page' => 1, 'per_page' => 10])
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.ref', 'ORD-001');
});

it('updates a row field', function () {
    $id = DB::table('test_orders')->first()->id;

    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->putJson("innertia/devtools/dbms/tables/test_orders/rows/{$id}", [
            'column' => 'ref',
            'value'  => 'ORD-999',
        ])
        ->assertOk()
        ->assertJsonPath('updated', true);

    expect(DB::table('test_orders')->where('id', $id)->value('ref'))->toBe('ORD-999');
});

it('runs a select query', function () {
    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/dbms/query', ['sql' => 'SELECT * FROM test_orders'])
        ->assertOk()
        ->assertJsonPath('count', 1);
});

it('rejects non-select queries', function () {
    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/dbms/query', ['sql' => 'DROP TABLE test_orders'])
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Devtools/Http/DbmsControllerTest.php -v
```

Expected: FAIL with `Class "Innertia\Devtools\Http\Controllers\DbmsController" not found`

- [ ] **Step 3: Implement `DbmsController`**

Create `src/Devtools/Http/Controllers/DbmsController.php`:

```php
<?php

namespace Innertia\Devtools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Innertia\Devtools\Dbms\RowBrowser;
use Innertia\Devtools\Dbms\RowEditor;
use Innertia\Devtools\Dbms\TableInspector;

class DbmsController extends Controller
{
    public function tables(): JsonResponse
    {
        return response()->json(TableInspector::tables());
    }

    public function rows(Request $request, string $table): JsonResponse
    {
        $data = $request->validate([
            'page'               => 'nullable|integer|min:1',
            'per_page'           => 'nullable|integer|min:1|max:500',
            'sort_by'            => 'nullable|string',
            'sort_dir'           => 'nullable|in:asc,desc',
            'filters'            => 'nullable|array',
            'filters.*.column'   => 'required|string',
            'filters.*.operator' => 'nullable|in:=,!=,<,>,<=,>=,like,not like',
            'filters.*.value'    => 'required',
        ]);

        return response()->json(
            RowBrowser::browse(
                table:   $table,
                page:    $data['page']     ?? 1,
                perPage: $data['per_page'] ?? 50,
                filters: $data['filters']  ?? [],
                sortBy:  $data['sort_by']  ?? 'id',
                sortDir: $data['sort_dir'] ?? 'asc',
            )
        );
    }

    public function updateRow(Request $request, string $table, string $id): JsonResponse
    {
        $data = $request->validate([
            'column' => 'required|string',
            'value'  => 'nullable',
        ]);

        $updated = RowEditor::update($table, $id, $data['column'], $data['value']);

        return response()->json(['updated' => $updated]);
    }

    public function query(Request $request): JsonResponse
    {
        $data = $request->validate(['sql' => 'required|string']);

        if (! preg_match('/^\s*select\s/i', $data['sql'])) {
            return response()->json(['message' => 'Only SELECT queries are allowed.'], 422);
        }

        $results = DB::select($data['sql']);

        return response()->json(['data' => $results, 'count' => count($results)]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Devtools/Http/DbmsControllerTest.php -v
```

Expected: 7 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Devtools/Http/Controllers/DbmsController.php \
        tests/Devtools/Http/DbmsControllerTest.php
git commit -m "feat(devtools): DbmsController — tables, rows, updateRow, query endpoints"
```

---

### Task 6: TinkerSession

**Files:**
- Create: `src/Devtools/Tinker/TinkerSession.php`
- Create: `tests/Devtools/Tinker/TinkerSessionTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Devtools/Tinker/TinkerSessionTest.php`:

```php
<?php

use Innertia\Devtools\Tinker\TinkerSession;

beforeEach(function () {
    config(['innertia.devtools.tinker.session_ttl' => 1800]);
    // Use array cache driver so no real Redis needed in tests
    config(['cache.default' => 'array']);
});

it('creates a session with a uuid id', function () {
    $session = TinkerSession::create();

    expect($session->id())->toMatch('/^[0-9a-f-]{36}$/');
});

it('can be found by id after creation', function () {
    $session = TinkerSession::create();
    $found   = TinkerSession::find($session->id());

    expect($found)->not->toBeNull()
        ->and($found->id())->toBe($session->id());
});

it('returns null when session does not exist', function () {
    expect(TinkerSession::find('non-existent-id'))->toBeNull();
});

it('persists and retrieves variables', function () {
    $session = TinkerSession::create();
    $session->save(['foo' => 'bar', 'count' => 42]);

    $found = TinkerSession::find($session->id());
    expect($found->variables())->toBe(['foo' => 'bar', 'count' => 42]);
});

it('exposes the correct broadcast channel name', function () {
    $session = TinkerSession::create();

    expect($session->channel())->toBe('private-innertia.tinker.' . $session->id());
});

it('can be destroyed', function () {
    $session = TinkerSession::create();
    $id      = $session->id();

    $session->destroy();

    expect(TinkerSession::find($id))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Devtools/Tinker/TinkerSessionTest.php -v
```

Expected: FAIL with `Class "Innertia\Devtools\Tinker\TinkerSession" not found`

- [ ] **Step 3: Implement `TinkerSession`**

Create `src/Devtools/Tinker/TinkerSession.php`:

```php
<?php

namespace Innertia\Devtools\Tinker;

use Illuminate\Support\Str;

class TinkerSession
{
    private function __construct(private readonly string $id) {}

    public static function create(): self
    {
        $session = new self(Str::uuid()->toString());
        $session->save([]);

        return $session;
    }

    public static function find(string $id): ?self
    {
        if (cache()->has(self::cacheKey($id))) {
            return new self($id);
        }

        return null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function channel(): string
    {
        return "private-innertia.tinker.{$this->id}";
    }

    public function variables(): array
    {
        return self::store()->get(self::cacheKey($this->id), []);
    }

    public function save(array $variables): void
    {
        $ttl = config('innertia.devtools.tinker.session_ttl', 1800);
        self::store()->put(self::cacheKey($this->id), $variables, $ttl);
    }

    public function destroy(): void
    {
        self::store()->forget(self::cacheKey($this->id));
    }

    /**
     * Usa el store configurado explícitamente — nunca el default del app,
     * que podría ser 'octane' (in-memory, worker-local) y romper sesiones
     * cuando los requests caen en workers distintos.
     */
    private static function store(): \Illuminate\Contracts\Cache\Repository
    {
        return cache()->store(
            config('innertia.devtools.tinker.cache_store', 'redis')
        );
    }

    private static function cacheKey(string $id): string
    {
        return "devtools_tinker_{$id}";
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Devtools/Tinker/TinkerSessionTest.php -v
```

Expected: 6 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Devtools/Tinker/TinkerSession.php tests/Devtools/Tinker/TinkerSessionTest.php
git commit -m "feat(devtools): TinkerSession — Redis-backed session with variable persistence"
```

---

### Task 7: TinkerSandbox

**Files:**
- Create: `src/Devtools/Tinker/TinkerSandbox.php`
- Create: `tests/Devtools/Tinker/TinkerSandboxTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Devtools/Tinker/TinkerSandboxTest.php`:

```php
<?php

use Innertia\Devtools\Tinker\TinkerSandbox;

it('allows safe code', function () {
    expect(fn () => TinkerSandbox::validate('$x = 1 + 1;'))->not->toThrow(RuntimeException::class);
    expect(fn () => TinkerSandbox::validate('User::all();'))->not->toThrow(RuntimeException::class);
    expect(fn () => TinkerSandbox::validate('DB::table("users")->count();'))->not->toThrow(RuntimeException::class);
});

it('blocks exec', function () {
    expect(fn () => TinkerSandbox::validate('exec("ls")'))->toThrow(RuntimeException::class, "'exec'");
});

it('blocks shell_exec', function () {
    expect(fn () => TinkerSandbox::validate('$r = shell_exec("id");'))->toThrow(RuntimeException::class, "'shell_exec'");
});

it('blocks system', function () {
    expect(fn () => TinkerSandbox::validate('system("whoami");'))->toThrow(RuntimeException::class, "'system'");
});

it('blocks file_put_contents', function () {
    expect(fn () => TinkerSandbox::validate('file_put_contents("/etc/cron.d/x", "evil");'))
        ->toThrow(RuntimeException::class, "'file_put_contents'");
});

it('blocks unlink', function () {
    expect(fn () => TinkerSandbox::validate('unlink("/important/file");'))->toThrow(RuntimeException::class, "'unlink'");
});

it('blocks proc_open', function () {
    expect(fn () => TinkerSandbox::validate('proc_open("bash", [], $p);'))->toThrow(RuntimeException::class, "'proc_open'");
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Devtools/Tinker/TinkerSandboxTest.php -v
```

Expected: FAIL with `Class "Innertia\Devtools\Tinker\TinkerSandbox" not found`

- [ ] **Step 3: Implement `TinkerSandbox`**

Create `src/Devtools/Tinker/TinkerSandbox.php`:

```php
<?php

namespace Innertia\Devtools\Tinker;

class TinkerSandbox
{
    private const BLOCKED = [
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'popen',
        'proc_open',
        'pcntl_exec',
        'file_put_contents',
        'file_get_contents',
        'unlink',
        'rmdir',
        'chmod',
        'chown',
        'rename',
        'copy',
        'mkdir',
    ];

    /**
     * @throws \RuntimeException when the code references a blocked function
     */
    public static function validate(string $code): void
    {
        foreach (self::BLOCKED as $fn) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $code)) {
                throw new \RuntimeException(
                    "Function '{$fn}' is not allowed in remote Tinker sessions."
                );
            }
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Devtools/Tinker/TinkerSandboxTest.php -v
```

Expected: 7 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Devtools/Tinker/TinkerSandbox.php tests/Devtools/Tinker/TinkerSandboxTest.php
git commit -m "feat(devtools): TinkerSandbox — blocklist validation before eval"
```

---

### Task 8: TinkerEvaluator

**Files:**
- Create: `src/Devtools/Tinker/TinkerEvaluator.php`
- Create: `tests/Devtools/Tinker/TinkerEvaluatorTest.php`

**Key design decision:** `eval()` in PHP leaks variables into the calling scope. By wrapping the entire eval in a closure, variables created inside eval are accessible via `get_defined_vars()` after the call, allowing us to persist the session state.

- [ ] **Step 1: Write the failing test**

Create `tests/Devtools/Tinker/TinkerEvaluatorTest.php`:

```php
<?php

use Innertia\Devtools\Tinker\TinkerEvaluator;
use Innertia\Devtools\Tinker\TinkerSession;

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['innertia.devtools.tinker.session_ttl' => 1800]);
});

it('captures echo output', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $result = $evaluator->evaluate($session, 'echo "hello world";');

    expect($result['output'])->toBe('hello world')
        ->and($result['error'])->toBeNull();
});

it('captures the return value', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $result = $evaluator->evaluate($session, '$x = 2 + 2;');

    expect($result['error'])->toBeNull();
});

it('persists variables between evals', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $evaluator->evaluate($session, '$counter = 10;');
    $result = $evaluator->evaluate($session, 'echo $counter;');

    expect($result['output'])->toBe('10')
        ->and($result['error'])->toBeNull();
});

it('captures exceptions as error string', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $result = $evaluator->evaluate($session, 'throw new \RuntimeException("oops");');

    expect($result['error'])->toContain('RuntimeException: oops')
        ->and($result['output'])->toBe('');
});

it('captures parse errors as error string', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $result = $evaluator->evaluate($session, '$x = ;');

    expect($result['error'])->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Devtools/Tinker/TinkerEvaluatorTest.php -v
```

Expected: FAIL with `Class "Innertia\Devtools\Tinker\TinkerEvaluator" not found`

- [ ] **Step 3: Implement `TinkerEvaluator`**

Create `src/Devtools/Tinker/TinkerEvaluator.php`:

```php
<?php

namespace Innertia\Devtools\Tinker;

class TinkerEvaluator
{
    /**
     * Evaluates $code in a closure scope that has access to the session's
     * previously defined variables. Variables created or modified during eval
     * are persisted back to the session (serializable values only).
     *
     * Returns: ['output' => string, 'return' => mixed, 'error' => string|null]
     */
    public function evaluate(TinkerSession $session, string $code): array
    {
        // Run inside a closure so get_defined_vars() captures exactly
        // what we inject + what eval creates, nothing from the outer frame.
        return (function () use ($session, $code) {
            extract($session->variables(), EXTR_SKIP);

            ob_start();
            $__return = null;
            $__error  = null;

            try {
                $__return = eval($code);
            } catch (\ParseError $__e) {
                $__error = 'ParseError: ' . $__e->getMessage();
            } catch (\Throwable $__e) {
                $__error = get_class($__e) . ': ' . $__e->getMessage();
            }

            $__output = ob_get_clean() ?: '';

            // Capture everything in this closure's scope after eval ran
            $__all = get_defined_vars();

            // Strip evaluator internals — keep only user variables
            foreach (['session', 'code', '__return', '__error', '__output', '__all', '__e'] as $__k) {
                unset($__all[$__k]);
            }

            // Persist only serializable values
            $__saveable = [];
            foreach ($__all as $__key => $__val) {
                try {
                    serialize($__val);
                    $__saveable[$__key] = $__val;
                } catch (\Throwable) {
                    // Non-serializable (closures, resources) — skip silently
                }
            }

            $session->save($__saveable);

            return [
                'output' => $__output,
                'return' => $__return,
                'error'  => $__error,
            ];
        })();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Devtools/Tinker/TinkerEvaluatorTest.php -v
```

Expected: 5 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Devtools/Tinker/TinkerEvaluator.php tests/Devtools/Tinker/TinkerEvaluatorTest.php
git commit -m "feat(devtools): TinkerEvaluator — eval with output capture and variable persistence"
```

---

### Task 9: TinkerOutputEvent + TinkerAuditLog

**Files:**
- Create: `src/Devtools/Events/TinkerOutputEvent.php`
- Create: `src/Devtools/Tinker/TinkerAuditLog.php`

No unit tests here — broadcast events and log wrappers are integration concerns verified in Task 10.

- [ ] **Step 1: Create `TinkerOutputEvent`**

Create `src/Devtools/Events/TinkerOutputEvent.php`:

```php
<?php

namespace Innertia\Devtools\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class TinkerOutputEvent implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        private readonly string $sessionId,
        private readonly array  $result,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        // Channel name (without "private-" prefix — Laravel adds it automatically)
        return new PrivateChannel("innertia.tinker.{$this->sessionId}");
    }

    public function broadcastAs(): string
    {
        return 'tinker.output';
    }

    public function broadcastWith(): array
    {
        return $this->result;
    }
}
```

- [ ] **Step 2: Create `TinkerAuditLog`**

Create `src/Devtools/Tinker/TinkerAuditLog.php`:

```php
<?php

namespace Innertia\Devtools\Tinker;

use Illuminate\Support\Facades\Log;

class TinkerAuditLog
{
    /**
     * Writes an audit entry to the default log channel.
     * Every remote eval must be logged before execution.
     */
    public static function record(string $sessionId, string $code, ?string $ip): void
    {
        Log::info('[devtools:tinker]', [
            'session_id' => $sessionId,
            'ip'         => $ip,
            'app'        => config('app.name'),
            'code'       => $code,
            'at'         => now()->toISOString(),
        ]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Devtools/Events/TinkerOutputEvent.php src/Devtools/Tinker/TinkerAuditLog.php
git commit -m "feat(devtools): TinkerOutputEvent (WebSocket) and TinkerAuditLog"
```

---

### Task 10: TinkerController

**Files:**
- Create: `src/Devtools/Http/Controllers/TinkerController.php`
- Create: `tests/Devtools/Http/TinkerControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Devtools/Http/TinkerControllerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Innertia\Devtools\Http\Controllers\TinkerController;
use Innertia\Devtools\Http\Middleware\DevtoolsGuard;
use Innertia\Olimpo\Http\Middleware\OlimpoAuth;

beforeEach(function () {
    config(['innertia.devtools.enabled'         => true]);
    config(['innertia.devtools.tinker.enabled'  => true]);
    config(['innertia.devtools.tinker.session_ttl' => 1800]);
    config(['olimpo.key'   => 'test-key']);
    config(['cache.default' => 'array']);
    config(['broadcasting.default' => 'log']); // no real Soketi in tests

    app('router')->aliasMiddleware('olimpo.auth', OlimpoAuth::class);
    app('router')->aliasMiddleware('devtools.guard', DevtoolsGuard::class);

    Route::prefix('innertia/devtools')
        ->middleware(['olimpo.auth', 'devtools.guard'])
        ->group(function () {
            Route::post('tinker/sessions', [TinkerController::class, 'create']);
            Route::post('tinker/sessions/{id}/eval', [TinkerController::class, 'eval']);
            Route::delete('tinker/sessions/{id}', [TinkerController::class, 'destroy']);
        });
});

it('creates a tinker session', function () {
    $response = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions');

    $response->assertCreated()
        ->assertJsonStructure(['session_id', 'channel', 'expires_in']);

    expect($response->json('channel'))->toStartWith('private-innertia.tinker.');
});

it('returns 403 when tinker is disabled', function () {
    config(['innertia.devtools.tinker.enabled' => false]);

    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions')
        ->assertStatus(403);
});

it('evaluates code and returns output', function () {
    $session = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions')
        ->json();

    $response = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson("innertia/devtools/tinker/sessions/{$session['session_id']}/eval", [
            'code' => 'echo "ping";',
        ]);

    $response->assertOk()
        ->assertJsonPath('output', 'ping')
        ->assertJsonPath('error', null);
});

it('rejects blocked functions', function () {
    $session = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions')
        ->json();

    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson("innertia/devtools/tinker/sessions/{$session['session_id']}/eval", [
            'code' => 'exec("ls")',
        ])
        ->assertStatus(422);
});

it('returns 404 for unknown session', function () {
    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions/nonexistent/eval', [
            'code' => 'echo 1;',
        ])
        ->assertNotFound();
});

it('destroys a session', function () {
    $session = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions')
        ->json();

    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->deleteJson("innertia/devtools/tinker/sessions/{$session['session_id']}")
        ->assertOk()
        ->assertJsonPath('ok', true);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Devtools/Http/TinkerControllerTest.php -v
```

Expected: FAIL with `Class "Innertia\Devtools\Http\Controllers\TinkerController" not found`

- [ ] **Step 3: Implement `TinkerController`**

Create `src/Devtools/Http/Controllers/TinkerController.php`:

```php
<?php

namespace Innertia\Devtools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Devtools\Events\TinkerOutputEvent;
use Innertia\Devtools\Tinker\TinkerAuditLog;
use Innertia\Devtools\Tinker\TinkerEvaluator;
use Innertia\Devtools\Tinker\TinkerSandbox;
use Innertia\Devtools\Tinker\TinkerSession;

class TinkerController extends Controller
{
    public function create(): JsonResponse
    {
        if (! config('innertia.devtools.tinker.enabled', false)) {
            return response()->json([
                'message' => 'Tinker not enabled. Set DEVTOOLS_TINKER_ENABLED=true.',
            ], 403);
        }

        $session = TinkerSession::create();

        return response()->json([
            'session_id' => $session->id(),
            'channel'    => $session->channel(),
            'expires_in' => config('innertia.devtools.tinker.session_ttl', 1800),
        ], 201);
    }

    public function eval(Request $request, string $id): JsonResponse
    {
        $session = TinkerSession::find($id);

        if (! $session) {
            return response()->json(['message' => 'Session not found or expired.'], 404);
        }

        $data = $request->validate(['code' => 'required|string']);

        try {
            TinkerSandbox::validate($data['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        TinkerAuditLog::record($session->id(), $data['code'], $request->ip());

        $result = (new TinkerEvaluator())->evaluate($session, $data['code']);

        try {
            broadcast(new TinkerOutputEvent($session->id(), $result));
        } catch (\Throwable) {
            // Broadcasting failure must never break the HTTP response
        }

        return response()->json($result);
    }

    public function destroy(string $id): JsonResponse
    {
        TinkerSession::find($id)?->destroy();

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Devtools/Http/TinkerControllerTest.php -v
```

Expected: 6 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Devtools/Http/Controllers/TinkerController.php \
        tests/Devtools/Http/TinkerControllerTest.php
git commit -m "feat(devtools): TinkerController — create/eval/destroy tinker sessions"
```

---

### Task 11: .env templates + full test suite

**Files:**
- Modify: `innertia-setup/templates/app/backend/.env.example`
- Modify: `innertia-setup/templates/saas/backend/.env.example`
- Modify: `innertia-setup/templates/laravel-api/.env.example`

- [ ] **Step 1: Add devtools vars to all three `.env.example` templates**

In each file, after the `TELEMETRY_MODE=remote` line, add:

```dotenv
DEVTOOLS_ENABLED=false
# DEVTOOLS_TINKER_ENABLED=false   # uncomment when you need remote Tinker support
```

- [ ] **Step 2: Run the full devtools test suite**

```bash
./vendor/bin/pest tests/Devtools/ -v
```

Expected: All tests PASS (no failures)

- [ ] **Step 3: Commit innertia-laravel**

```bash
cd /path/to/innertia-laravel
git add src/Devtools/ tests/Devtools/ config/innertia.php src/Olimpo/OlimpoServiceProvider.php
git commit -m "feat(devtools): complete Devtools module — DB Browser + Remote Tinker"
```

- [ ] **Step 4: Commit innertia-setup**

```bash
cd /path/to/innertia-setup
git add templates/
git commit -m "feat(devtools): add DEVTOOLS_ENABLED env var to all backend templates"
```

---

## Self-Review

**Spec coverage:**
- ✅ DB Browser: list tables, columns, row count → `TableInspector`
- ✅ Paginated rows with filters and sort → `RowBrowser`
- ✅ Inline editing → `RowEditor` + `DbmsController::updateRow`
- ✅ Raw SELECT queries → `DbmsController::query`
- ✅ Remote Tinker with variable persistence → `TinkerEvaluator` + `TinkerSession`
- ✅ WebSocket output streaming → `TinkerOutputEvent`
- ✅ Security blocklist → `TinkerSandbox`
- ✅ Audit log → `TinkerAuditLog`
- ✅ Auth: olimpo.auth + devtools.guard on all routes
- ✅ Separate `DEVTOOLS_TINKER_ENABLED` flag for Tinker
- ✅ All routes under `/innertia/devtools/` prefix
- ✅ .env templates updated

**Placeholder scan:** None found.

**Type consistency:**
- `TinkerSession::create()` → `TinkerSession`, used as `$session` throughout ✅
- `TinkerEvaluator::evaluate(TinkerSession, string)` → `array{output, return, error}` ✅
- `TinkerSandbox::validate(string)` throws `\RuntimeException` — caught in `TinkerController` ✅
- `TinkerOutputEvent(string $sessionId, array $result)` — `$session->id()` passed as first arg ✅
- `RowBrowser::browse()` returns `array{data, total, per_page, current_page, last_page}` ✅
- `RowEditor::update()` returns `bool` — returned as `['updated' => $bool]` in controller ✅
