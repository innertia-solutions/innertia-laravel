<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
