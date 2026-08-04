<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Observability\Routes;

uses(RefreshDatabase::class);

beforeEach(fn () => Routes::register('obs-q'));

it('overview y failed responden con shape', function () {
    $this->getJson('/obs-q/queues/overview')->assertOk()->assertJsonStructure(['depth' => ['available', 'queues'], 'failed']);
    $this->getJson('/obs-q/queues/failed')->assertOk()->assertJsonStructure(['jobs']);
});

it('retry/forget/flush responden ok sin lanzar', function () {
    $this->postJson('/obs-q/queues/failed/xxx/retry')->assertOk()->assertJsonStructure(['ok']);
    $this->deleteJson('/obs-q/queues/failed/xxx')->assertOk()->assertJsonStructure(['ok']);
    $this->postJson('/obs-q/queues/failed/flush')->assertOk()->assertJsonStructure(['ok']);
});
