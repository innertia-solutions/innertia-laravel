<?php

use Illuminate\Support\Facades\Route;
use Innertia\Tags\Models\Tag;

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaTagsMigrateUp();

    Route::middleware([])->group(function () {
        \Innertia\Tags\Routes::register();
    });
});

afterEach(fn () => innertiaTagsMigrateDown());

it('lists tags with pagination', function () {
    Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);
    Tag::create(['name' => 'VIP', 'slug' => 'vip']);

    $response = $this->getJson('/tags');

    $response->assertOk()->assertJsonCount(2, 'data');
});

it('filters tags by search', function () {
    Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);
    Tag::create(['name' => 'VIP', 'slug' => 'vip']);

    $response = $this->getJson('/tags?search=urg');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('returns popular tags ordered by usage count', function () {
    $tag1 = Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);
    $tag2 = Tag::create(['name' => 'VIP', 'slug' => 'vip']);

    \Illuminate\Support\Facades\DB::table('taggables')->insert([
        ['tag_id' => $tag1->id, 'taggable_type' => 'X', 'taggable_id' => \Illuminate\Support\Str::uuid(), 'tagged_at' => now()],
        ['tag_id' => $tag1->id, 'taggable_type' => 'X', 'taggable_id' => \Illuminate\Support\Str::uuid(), 'tagged_at' => now()],
        ['tag_id' => $tag2->id, 'taggable_type' => 'X', 'taggable_id' => \Illuminate\Support\Str::uuid(), 'tagged_at' => now()],
    ]);

    $response = $this->getJson('/tags/popular?limit=10');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['slug'])->toBe('urgente');
    expect($data[1]['slug'])->toBe('vip');
});

it('shows a single tag by id', function () {
    $tag = Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);

    $this->getJson("/tags/{$tag->id}")
        ->assertOk()
        ->assertJsonPath('data.slug', 'urgente');
});

it('creates a tag via POST', function () {
    $this->postJson('/tags', ['name' => 'Urgente', 'color' => '#ff0000'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'urgente')
        ->assertJsonPath('data.color', '#ff0000');

    expect(Tag::where('slug', 'urgente')->exists())->toBeTrue();
});

it('rejects invalid color format', function () {
    $this->postJson('/tags', ['name' => 'X', 'color' => 'red'])
        ->assertStatus(422);
});

it('rejects duplicate tag creation', function () {
    Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);

    $this->postJson('/tags', ['name' => 'urgente'])
        ->assertStatus(409);
});

it('updates a tag via PATCH', function () {
    $tag = Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);

    $this->patchJson("/tags/{$tag->id}", ['name' => 'Crítico'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'critico');
});

it('deletes a tag via DELETE', function () {
    $tag = Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);

    $this->deleteJson("/tags/{$tag->id}")
        ->assertNoContent();

    expect(Tag::find($tag->id))->toBeNull();
});

it('returns 404 on missing tag', function () {
    $this->getJson('/tags/00000000-0000-0000-0000-000000000000')
        ->assertNotFound();
});
