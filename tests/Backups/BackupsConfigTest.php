<?php

it('exposes backups config defaults', function () {
    expect(config('innertia.backups.mode'))->toBe('app');
    expect(config('innertia.backups.disk'))->toBe('backups');
    expect(config('innertia.backups.retention_days'))->toBe(30);
});

it('exposes the default schedule and retention', function () {
    expect(config('innertia.backups.keep'))->toBe(21);
    expect(config('innertia.backups.schedule.enabled'))->toBeTrue();
    expect(config('innertia.backups.schedule.times'))->toBe(['03:00', '11:00', '19:00']);
    expect(config('innertia.backups.schedule.timezone'))->toBe('America/Santiago');
});
