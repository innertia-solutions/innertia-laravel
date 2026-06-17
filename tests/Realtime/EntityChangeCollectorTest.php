<?php

use Illuminate\Support\Facades\Event;
use Innertia\Platform\Events\EntityChanged;
use Innertia\Platform\Realtime\EntityChangeCollector;

it('coalesces multiple records of the same table into one event on flush', function () {
    Event::fake([EntityChanged::class]);
    $c = app(EntityChangeCollector::class);

    $c->record('projects', 'updated', 'a');
    $c->record('projects', 'updated', 'b');
    $c->record('projects', 'created', 'a');     // id repetido → deduplica
    $c->record('schools', 'deleted', 'x');

    $c->flush();

    Event::assertDispatchedTimes(EntityChanged::class, 2);            // 1 por tabla
    Event::assertDispatched(EntityChanged::class, function ($e) {
        return $e->table === 'projects'
            && count($e->ids) === 2                                   // a, b deduplicados
            && in_array('updated', $e->actions, true)
            && in_array('created', $e->actions, true);
    });
});

it('touch() feeds the collector for bulk paths', function () {
    Event::fake([EntityChanged::class]);
    \Innertia\Facades\Realtime::touch('students', ['1', '2', '3'], 'created');
    app(EntityChangeCollector::class)->flush();
    Event::assertDispatched(EntityChanged::class, fn ($e) => $e->table === 'students' && count($e->ids) === 3);
});

it('caps ids in payload but keeps the full count signal', function () {
    Event::fake([EntityChanged::class]);
    $c = app(EntityChangeCollector::class);
    for ($i = 0; $i < 250; $i++) {
        $c->record('students', 'created', (string) $i);
    }
    $c->flush();
    Event::assertDispatched(EntityChanged::class, fn ($e) => count($e->ids) <= 100);  // tope defensivo
});
