<?php
// tests/Platform/HasBoardPositionTest.php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Innertia\Platform\Traits\HasBoardPosition;

class BpThing extends Model
{
    use HasBoardPosition;
    protected $table = 'bp_things';
    protected $guarded = [];
    public $timestamps = true;
    protected function boardColumnKey(): ?string { return 'col'; }
}

beforeEach(function () {
    Schema::create('bp_things', function ($t) {
        $t->increments('id');
        $t->string('col')->nullable();
        $t->double('position')->nullable();
        $t->timestamps();
    });
});
afterEach(fn () => Schema::dropIfExists('bp_things'));

it('auto-asigna position incremental por columna al crear', function () {
    $a = BpThing::create(['col' => 'x']);
    $b = BpThing::create(['col' => 'x']);
    $c = BpThing::create(['col' => 'y']);

    expect($a->position)->toBe((float) BpThing::BOARD_POSITION_STEP);
    expect($b->position)->toBe((float) BpThing::BOARD_POSITION_STEP * 2);
    expect($c->position)->toBe((float) BpThing::BOARD_POSITION_STEP);
});

it('scopeOrderByBoard ordena por position asc', function () {
    BpThing::create(['col' => 'x', 'position' => 30]);
    BpThing::create(['col' => 'x', 'position' => 10]);
    BpThing::create(['col' => 'x', 'position' => 20]);

    $positions = BpThing::orderByBoard()->pluck('position')->all();
    expect($positions)->toBe([10.0, 20.0, 30.0]);
});
