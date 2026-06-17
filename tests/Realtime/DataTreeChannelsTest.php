<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Innertia\DataTable\DataTree;

// ── Fixtures ─────────────────────────────────────────────────────────────────

class RtTreeThing extends Model
{
    protected $table = 'rt_things';
    protected $guarded = [];
    public $timestamps = false;
}

class RtTreeOther extends Model
{
    protected $table = 'rt_others';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);

    Schema::create('rt_things', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('parent_id')->nullable();
    });

    Schema::create('rt_others', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('parent_id')->nullable();
    });
});

function renderTreeMeta(DataTree $tree): array
{
    $req = Request::create('/', 'GET');
    $res = $tree->render($req);

    return json_decode($res->getContent(), true)['meta'] ?? [];
}

// ── Tests ────────────────────────────────────────────────────────────────────

it('declara meta.channels con el canal entity.{table} del modelo por defecto', function () {
    $tree = DataTree::create('rt_things', RtTreeThing::class)->columns(['name']);

    $meta = renderTreeMeta($tree);

    expect($meta['channels'])->toBe(['entity.rt_things']);
});

it('listen agrega canales de otras tablas', function () {
    $tree = DataTree::create('rt_things', RtTreeThing::class)
        ->columns(['name'])
        ->listen([RtTreeOther::class]);

    $meta = renderTreeMeta($tree);

    expect($meta['channels'])->toContain('entity.rt_things');
    expect($meta['channels'])->toContain('entity.rt_others');
});

it('realtime(false) omite los canales', function () {
    $tree = DataTree::create('rt_things', RtTreeThing::class)
        ->columns(['name'])
        ->realtime(false);

    $meta = renderTreeMeta($tree);

    expect($meta['channels'] ?? [])->toBe([]);
});
