<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Tags\Traits\HasTags;

// Test fixture model
class ScopeTestQuote extends Model {
    use HasUuids, HasTags;
    protected $table = 'scope_test_quotes';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');

    require_once __DIR__ . '/helpers/migrate.php';
    innertiaTagsMigrateUp();

    Schema::create('scope_test_quotes', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('title');
    });

    $this->a = ScopeTestQuote::create(['title' => 'A']);
    $this->b = ScopeTestQuote::create(['title' => 'B']);
    $this->c = ScopeTestQuote::create(['title' => 'C']);

    $this->a->tag('vip', 'urgente');
    $this->b->tag('urgente');
    $this->c->tag('archivado');
});

afterEach(function () {
    Schema::dropIfExists('scope_test_quotes');
    innertiaTagsMigrateDown();
});

it('withTag returns entities with exact tag', function () {
    $ids = ScopeTestQuote::withTag('urgente')->pluck('id')->sort()->values();
    expect($ids)->toEqual(collect([$this->a->id, $this->b->id])->sort()->values());
});

it('withAnyTag returns entities matching at least one', function () {
    $ids = ScopeTestQuote::withAnyTag(['vip', 'archivado'])->pluck('id')->sort()->values();
    expect($ids)->toEqual(collect([$this->a->id, $this->c->id])->sort()->values());
});

it('withAllTags returns entities matching every tag', function () {
    $ids = ScopeTestQuote::withAllTags(['vip', 'urgente'])->pluck('id');
    expect($ids->all())->toEqual([$this->a->id]);
});

it('withoutTag excludes entities with the tag', function () {
    $ids = ScopeTestQuote::withoutTag('urgente')->pluck('id');
    expect($ids->all())->toEqual([$this->c->id]);
});

it('withoutAnyTag excludes entities with any of the listed tags', function () {
    $ids = ScopeTestQuote::withoutAnyTag(['vip', 'archivado'])->pluck('id');
    expect($ids->all())->toEqual([$this->b->id]);
});
