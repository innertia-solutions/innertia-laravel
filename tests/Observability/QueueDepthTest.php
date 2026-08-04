<?php
use Innertia\Observability\Queues\QueueDepth;

it('devuelve la forma estable sin lanzar', function () {
    $d = QueueDepth::collect();
    expect($d)->toHaveKeys(['driver', 'available', 'queues', 'total']);
    expect($d['queues'])->toBeArray();
    expect($d['available'])->toBeBool();
});
