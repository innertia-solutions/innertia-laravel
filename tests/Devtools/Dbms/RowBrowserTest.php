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
