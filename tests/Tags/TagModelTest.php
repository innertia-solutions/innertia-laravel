<?php

use Illuminate\Support\Facades\Schema;
use Innertia\Tags\Exceptions\DuplicateTagException;
use Innertia\Tags\Models\Tag;

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app'); // skip tenant scope

    // Run the migration inline for testing
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaTagsMigrateUp();
});

afterEach(function () {
    innertiaTagsMigrateDown();
});

it('creates a tag with auto-generated slug', function () {
    $tag = Tag::findOrCreateByName('Urgente');

    expect($tag->slug)->toBe('urgente');
    expect($tag->name)->toBe('Urgente');
});

it('preserves accents in name but slugifies for slug', function () {
    $tag = Tag::findOrCreateByName('Próxima auditoría');

    expect($tag->name)->toBe('Próxima auditoría');
    expect($tag->slug)->toBe('proxima-auditoria');
});

it('returns existing tag when name matches by slug', function () {
    $first  = Tag::findOrCreateByName('Urgente');
    $second = Tag::findOrCreateByName('URGENTE');

    expect($second->id)->toBe($first->id);
});

it('rejects duplicate slug creation when forced via create', function () {
    // Use an explicit tenant_id so the composite unique index (tenant_id, slug)
    // is exercised correctly. SQLite (used in tests) treats (NULL, slug) rows as
    // distinct due to NULL semantics — the real constraint enforcement is on
    // non-NULL tenant_id pairs (matches PostgreSQL production behaviour).
    Tag::create(['name' => 'Urgente', 'slug' => 'urgente', 'tenant_id' => 'tenant-1']);

    expect(fn () => Tag::create(['name' => 'Otra', 'slug' => 'urgente', 'tenant_id' => 'tenant-1']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('uses custom slug_generator from config when provided', function () {
    config()->set('innertia.tags.slug_generator', fn ($name) => 'custom-' . strtolower($name));

    $tag = Tag::findOrCreateByName('Urgente');

    expect($tag->slug)->toBe('custom-urgente');
});
