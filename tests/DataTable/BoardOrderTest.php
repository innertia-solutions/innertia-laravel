<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Innertia\DataTable\DataTable;
use Innertia\Platform\Traits\HasBoardPosition;

// ── Fixture ───────────────────────────────────────────────────────────────────

class BoThing extends Model
{
    use HasBoardPosition;

    protected $table = 'bo_things';
    protected $guarded = [];
    public $timestamps = true;

    protected function boardColumnKey(): ?string
    {
        return 'col';
    }
}

// ── Setup / teardown ──────────────────────────────────────────────────────────

beforeEach(function () {
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);

    Schema::create('bo_things', function (Blueprint $t) {
        $t->increments('id');
        $t->string('name')->nullable();
        $t->string('col')->nullable();
        $t->double('position')->nullable();
        $t->timestamps();
    });
});

afterEach(fn () => Schema::dropIfExists('bo_things'));

// ── Tests ─────────────────────────────────────────────────────────────────────

it('con board=true ordena por position; sin él usa el sort por defecto', function () {
    BoThing::create(['name' => 'A', 'col' => 'x', 'position' => 300]);
    BoThing::create(['name' => 'B', 'col' => 'x', 'position' => 100]);
    BoThing::create(['name' => 'C', 'col' => 'x', 'position' => 200]);

    // board=true → ordena por position asc (100, 200, 300 → B, C, A)
    $boardReq = Request::create('/', 'POST', ['board' => true, 'list' => true]);
    $ordered = (new DataTable('bo_things'))->columns(['name', 'position'])->render(BoThing::class, $boardReq, 'created_at', 'desc');
    $names = collect($ordered->getData()->data)->pluck('name')->all();
    expect($names)->toBe(['B', 'C', 'A']);

    // sin board → usa el sort por defecto (position desc → 300, 200, 100 → A, C, B)
    $plainReq = Request::create('/', 'POST', ['list' => true]);
    $plain = (new DataTable('bo_things'))->columns(['name', 'position'])->render(BoThing::class, $plainReq, 'position', 'desc');
    $namesPlain = collect($plain->getData()->data)->pluck('name')->all();
    expect($namesPlain)->toBe(['A', 'C', 'B']);
});

it('en board mode incluye position en el payload aunque no esté en columns()', function () {
    BoThing::create(['name' => 'A', 'col' => 'x', 'position' => 100]);

    // columns() SIN position (como los controllers reales) → board mode debe agregarlo.
    $boardReq = Request::create('/', 'POST', ['board' => true, 'list' => true]);
    $res = (new DataTable('bo_things'))->columns(['name'])->render(BoThing::class, $boardReq, 'created_at', 'desc');
    $row = $res->getData()->data[0];

    // Presente en el payload (el DataTable serializa valores crudos, sin cast → puede ser int/float según driver).
    expect($row->position)->not->toBeNull();
    expect((float) $row->position)->toBe(100.0);
});
