<?php

use Innertia\Observability\Routes;

beforeEach(function () {
    Routes::register('obs-test');
});

it('overview responde shape', function () {
    $this->getJson('/obs-test/database/overview')
        ->assertOk()->assertJsonStructure(['driver', 'cache_hit_ratio', 'commits']);
});

it('queries responde available', function () {
    $this->getJson('/obs-test/database/queries')
        ->assertOk()->assertJsonStructure(['available', 'top']);
});

it('connections/indexes/tables responden', function () {
    $this->getJson('/obs-test/database/connections')->assertOk()->assertJsonStructure(['by_state']);
    $this->getJson('/obs-test/database/indexes')->assertOk()->assertJsonStructure(['unused', 'scans']);
    $this->getJson('/obs-test/database/tables')->assertOk()->assertJsonStructure(['tables']);
});
