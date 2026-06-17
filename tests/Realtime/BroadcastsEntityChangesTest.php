<?php

use Illuminate\Support\Facades\Event;
use Innertia\Platform\Events\EntityChanged;
use Innertia\Platform\Realtime\EntityChangeCollector;
use Innertia\Platform\Traits\BroadcastsEntityChanges;
use Illuminate\Database\Eloquent\Model;

class RtThing extends Model
{
    use BroadcastsEntityChanges;
    protected $table = 'rt_things';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    \Illuminate\Support\Facades\Schema::create('rt_things', function ($t) {
        $t->increments('id');
        $t->string('name')->nullable();
    });
});

afterEach(function () {
    \Illuminate\Support\Facades\Schema::dropIfExists('rt_things');
});

it('records on created/updated/deleted and emits one event per table on flush', function () {
    Event::fake([EntityChanged::class]);

    $a = RtThing::create(['name' => 'x']);
    $a->update(['name' => 'y']);
    $a->delete();

    app(EntityChangeCollector::class)->flush();

    Event::assertDispatchedTimes(EntityChanged::class, 1);            // coalescido en rt_things
    Event::assertDispatched(EntityChanged::class, function ($e) {
        return $e->table === 'rt_things'
            && in_array('created', $e->actions, true)
            && in_array('updated', $e->actions, true)
            && in_array('deleted', $e->actions, true);
    });
});
