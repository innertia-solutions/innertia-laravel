<?php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Innertia\Platform\Traits\HasBoardPosition;
use Innertia\Platform\Board\ReorderEntity;

class ReThing extends Model
{
    use HasBoardPosition;
    protected $table = 're_things';
    protected $guarded = [];
    public $timestamps = true;
    protected function boardColumnKey(): ?string { return 'col'; }
}

beforeEach(function () {
    Schema::create('re_things', function ($t) {
        $t->increments('id');
        $t->string('col')->nullable();
        $t->double('position')->nullable();
        $t->timestamps();
    });
});
afterEach(fn () => Schema::dropIfExists('re_things'));

it('inserta entre dos vecinos usando el promedio', function () {
    $a = ReThing::create(['col' => 'x', 'position' => 1000]);
    $b = ReThing::create(['col' => 'x', 'position' => 2000]);
    $moved = ReThing::create(['col' => 'x', 'position' => 3000]);

    (new ReorderEntity($moved, beforeId: (string) $a->id, afterId: (string) $b->id))->execute();

    expect($moved->fresh()->position)->toBe(1500.0);
});

it('al final de la columna suma un paso al último', function () {
    $a = ReThing::create(['col' => 'x', 'position' => 1000]);
    $moved = ReThing::create(['col' => 'x', 'position' => 500]);

    (new ReorderEntity($moved, beforeId: (string) $a->id, afterId: null))->execute();

    expect($moved->fresh()->position)->toBe(1000.0 + ReThing::BOARD_POSITION_STEP);
});

it('al inicio de la columna resta un paso al primero', function () {
    $a = ReThing::create(['col' => 'x', 'position' => 1000]);
    $moved = ReThing::create(['col' => 'x', 'position' => 5000]);

    (new ReorderEntity($moved, beforeId: null, afterId: (string) $a->id))->execute();

    expect($moved->fresh()->position)->toBe(1000.0 - ReThing::BOARD_POSITION_STEP);
});

it('trata un before_id inexistente como sin-vecino (no cae en 0)', function () {
    $a = ReThing::create(['col' => 'x', 'position' => 1000]);
    $moved = ReThing::create(['col' => 'x', 'position' => 5000]);

    // before_id inexistente + after = $a → debe caer arriba de $a (after - step), no en ~0.
    (new ReorderEntity($moved, beforeId: '999999', afterId: (string) $a->id))->execute();

    expect($moved->fresh()->position)->toBe(1000.0 - ReThing::BOARD_POSITION_STEP);
});

it('rebalancea cuando los vecinos colisionan por precisión', function () {
    $a = ReThing::create(['col' => 'x', 'position' => 1.0000001]);
    $b = ReThing::create(['col' => 'x', 'position' => 1.0000002]);
    $moved = ReThing::create(['col' => 'x', 'position' => 9999]);

    (new ReorderEntity($moved, beforeId: (string) $a->id, afterId: (string) $b->id))->execute();

    $ordered = ReThing::where('col', 'x')->orderByBoard()->pluck('id')->all();
    $idx = array_search($moved->id, $ordered, true);
    expect($ordered[$idx - 1])->toBe($a->id);
    expect($ordered[$idx + 1])->toBe($b->id);
});
