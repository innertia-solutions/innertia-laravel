<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Tags\Traits\HasTags;
use Innertia\Tags\UseCases\AttachTags;
use Innertia\Tags\UseCases\DetachTags;
use Innertia\Tags\UseCases\SyncTags;

class UCQuote extends Model {
    use HasUuids, HasTags;
    protected $table = 'uc_quotes';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaTagsMigrateUp();

    Schema::create('uc_quotes', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('title');
    });
});

afterEach(function () {
    Schema::dropIfExists('uc_quotes');
    innertiaTagsMigrateDown();
});

it('attaches multiple tags to an entity', function () {
    $quote = UCQuote::create(['title' => 'X']);

    (new AttachTags(entity: $quote, names: ['vip', 'urgente']))->execute();

    expect($quote->fresh()->tags)->toHaveCount(2);
});

it('detaches tags from an entity', function () {
    $quote = UCQuote::create(['title' => 'X']);
    $quote->tag('vip', 'urgente', 'archivado');

    (new DetachTags(entity: $quote, names: ['vip', 'archivado']))->execute();

    expect($quote->fresh()->tags->pluck('slug')->all())->toEqual(['urgente']);
});

it('sync replaces the full tag set', function () {
    $quote = UCQuote::create(['title' => 'X']);
    $quote->tag('vip', 'urgente');

    (new SyncTags(entity: $quote, names: ['nuevo', 'lista']))->execute();

    expect($quote->fresh()->tags->pluck('slug')->sort()->values()->all())
        ->toEqual(['lista', 'nuevo']);
});
