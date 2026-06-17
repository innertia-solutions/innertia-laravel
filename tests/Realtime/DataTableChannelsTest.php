<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Innertia\DataTable\DataTable;

// ── Fixtures ─────────────────────────────────────────────────────────────────

class RtChanThing extends Model
{
    protected $table = 'rt_things';
    protected $guarded = [];
    public $timestamps = false;
}

class RtChanOther extends Model
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
    });

    Schema::create('rt_others', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    RtChanThing::insert([
        ['id' => 1, 'name' => 'a'],
        ['id' => 2, 'name' => 'b'],
    ]);
});

function renderMeta(DataTable $dt): array
{
    $req = Request::create('/', 'GET', ['perPage' => 50]);
    $res = $dt->render(RtChanThing::query(), $req);

    return json_decode($res->getContent(), true)['meta'] ?? [];
}

// ── Tests ────────────────────────────────────────────────────────────────────

it('declara meta.channels con el canal entity.{table} del modelo por defecto', function () {
    $dt = (new DataTable('rt_things'))->columns(['name']);

    $meta = renderMeta($dt);

    expect($meta['channels'])->toBe(['entity.rt_things']);
});

it('realtimeListen agrega canales de otras tablas', function () {
    $dt = (new DataTable('rt_things'))
        ->columns(['name'])
        ->realtimeListen([RtChanOther::class]);

    $meta = renderMeta($dt);

    expect($meta['channels'])->toContain('entity.rt_things');
    expect($meta['channels'])->toContain('entity.rt_others');
});

it('realtime(false) omite los canales', function () {
    $dt = (new DataTable('rt_things'))
        ->columns(['name'])
        ->realtime(false);

    $meta = renderMeta($dt);

    expect($meta['channels'] ?? [])->toBe([]);
});
