<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Observability\Routes;

uses(RefreshDatabase::class);

beforeEach(fn () => Routes::register('obs-f2'));

it('overview y errors index responden', function () {
    $this->getJson('/obs-f2/overview')->assertOk()->assertJsonStructure(['errors', 'database']);
    $this->getJson('/obs-f2/errors')->assertOk();
});
