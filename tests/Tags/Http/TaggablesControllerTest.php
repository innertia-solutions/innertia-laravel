<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Innertia\Tags\Traits\HasTags;

class HttpQuote extends Model {
    use HasUuids, HasTags;
    protected $table = 'http_quotes';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    config()->set('innertia.tags.taggable_types', [
        'quotes' => HttpQuote::class,
    ]);
    config()->set('innertia.tags.authorize_attach', fn ($u, $m) => true);

    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaTagsMigrateUp();

    Schema::create('http_quotes', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('title');
    });

    Route::middleware([])->group(function () {
        \Innertia\Tags\Routes::register();
    });
});

afterEach(function () {
    Schema::dropIfExists('http_quotes');
    innertiaTagsMigrateDown();
});

it('lists tags of an entity', function () {
    $quote = HttpQuote::create(['title' => 'X']);
    $quote->tag('vip', 'urgente');

    $this->getJson("/taggables/quotes/{$quote->id}/tags")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns 404 for unknown taggable type', function () {
    $this->getJson('/taggables/unknown-type/00000000-0000-0000-0000-000000000000/tags')
        ->assertNotFound();
});

it('returns 404 for unknown entity id', function () {
    $this->getJson('/taggables/quotes/00000000-0000-0000-0000-000000000000/tags')
        ->assertNotFound();
});

it('attaches tags to an entity via POST', function () {
    $quote = HttpQuote::create(['title' => 'X']);

    $this->postJson("/taggables/quotes/{$quote->id}/tags", ['tags' => ['vip', 'urgente']])
        ->assertOk();

    expect($quote->fresh()->tags)->toHaveCount(2);
});

it('detaches a single tag via DELETE', function () {
    $quote = HttpQuote::create(['title' => 'X']);
    $quote->tag('vip', 'urgente');
    $vipId = $quote->tags->firstWhere('slug', 'vip')->id;

    $this->deleteJson("/taggables/quotes/{$quote->id}/tags/{$vipId}")
        ->assertNoContent();

    expect($quote->fresh()->tags->pluck('slug')->all())->toEqual(['urgente']);
});

it('syncs (replaces) tags via PUT', function () {
    $quote = HttpQuote::create(['title' => 'X']);
    $quote->tag('a', 'b');

    $this->putJson("/taggables/quotes/{$quote->id}/tags", ['tags' => ['x', 'y']])
        ->assertOk();

    expect($quote->fresh()->tags->pluck('slug')->sort()->values()->all())->toEqual(['x', 'y']);
});

it('returns 403 when authorize_attach callback rejects', function () {
    config()->set('innertia.tags.authorize_attach', fn ($u, $m) => false);

    $quote = HttpQuote::create(['title' => 'X']);

    $this->postJson("/taggables/quotes/{$quote->id}/tags", ['tags' => ['vip']])
        ->assertForbidden();
});

it('rejects empty tags array on attach', function () {
    $quote = HttpQuote::create(['title' => 'X']);

    $this->postJson("/taggables/quotes/{$quote->id}/tags", ['tags' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tags']);
});

it('returns 403 when no user and no callback configured', function () {
    config()->set('innertia.tags.authorize_attach', null);
    $quote = HttpQuote::create(['title' => 'X']);

    $this->postJson("/taggables/quotes/{$quote->id}/tags", ['tags' => ['vip']])
        ->assertForbidden();
});
