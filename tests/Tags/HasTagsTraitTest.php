<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Tags\Models\Tag;
use Innertia\Tags\Traits\HasTags;

// Test fixture model
class TaggableQuote extends Model {
    use HasUuids, HasTags;
    protected $table = 'taggable_quotes';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');

    require_once __DIR__ . '/helpers/migrate.php';
    innertiaTagsMigrateUp();

    Schema::create('taggable_quotes', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('title');
    });
});

afterEach(function () {
    Schema::dropIfExists('taggable_quotes');
    innertiaTagsMigrateDown();
});

it('attaches tags by name and creates missing tags', function () {
    $quote = TaggableQuote::create(['title' => 'Cotización A']);

    $quote->tag('Urgente', 'VIP');

    expect($quote->tags)->toHaveCount(2);
    expect(Tag::pluck('slug')->all())->toEqual(['urgente', 'vip']);
});

it('accepts array form for tag()', function () {
    $quote = TaggableQuote::create(['title' => 'Cotización A']);

    $quote->tag(['urgente', 'vip']);

    expect($quote->fresh()->tags)->toHaveCount(2);
});

it('tag() is idempotent — re-attaching does not duplicate', function () {
    $quote = TaggableQuote::create(['title' => 'Cotización A']);

    $quote->tag('Urgente');
    $quote->tag('Urgente');

    expect($quote->fresh()->tags)->toHaveCount(1);
});

it('untags by name', function () {
    $quote = TaggableQuote::create(['title' => 'Cotización A']);
    $quote->tag('urgente', 'vip');

    $quote->untag('urgente');

    expect($quote->fresh()->tags->pluck('slug')->all())->toEqual(['vip']);
});

it('retag replaces the full set', function () {
    $quote = TaggableQuote::create(['title' => 'Cotización A']);
    $quote->tag('a', 'b', 'c');

    $quote->retag(['x', 'y']);

    expect($quote->fresh()->tags->pluck('slug')->sort()->values()->all())->toEqual(['x', 'y']);
});

it('clearTags removes all', function () {
    $quote = TaggableQuote::create(['title' => 'Cotización A']);
    $quote->tag('a', 'b');

    $quote->clearTags();

    expect($quote->fresh()->tags)->toHaveCount(0);
});

it('throws FeatureDisabledException when feature is disabled', function () {
    config()->set('innertia.tags.enabled', false);
    $quote = TaggableQuote::create(['title' => 'X']);

    expect(fn () => $quote->tag('vip'))
        ->toThrow(\Innertia\Tags\Exceptions\FeatureDisabledException::class);
});

it('hasTag, hasAnyTag, hasAllTags work correctly', function () {
    $quote = TaggableQuote::create(['title' => 'Cotización A']);
    $quote->tag('vip', 'urgente');

    expect($quote->hasTag('vip'))->toBeTrue();
    expect($quote->hasTag('archivado'))->toBeFalse();
    expect($quote->hasAnyTag(['urgente', 'spam']))->toBeTrue();
    expect($quote->hasAllTags(['vip', 'urgente']))->toBeTrue();
    expect($quote->hasAllTags(['vip', 'pagado']))->toBeFalse();
});
