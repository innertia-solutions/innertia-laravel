<?php

it('exposes backups config defaults', function () {
    expect(config('innertia.backups.mode'))->toBe('app');
    expect(config('innertia.backups.disk'))->toBe('backups');
    // Horarios/retención no son config de archivo: viven en settings (/platform).
    expect(config('innertia.backups'))->not->toHaveKeys(['retention_days', 'keep', 'schedule']);
});

