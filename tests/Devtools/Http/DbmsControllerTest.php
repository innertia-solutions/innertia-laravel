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
