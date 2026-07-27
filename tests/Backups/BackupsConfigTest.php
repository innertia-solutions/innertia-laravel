<?php

it('exposes backups config defaults', function () {
    expect(config('innertia.backups.mode'))->toBe('app');
    expect(config('innertia.backups.disk'))->toBe('backups');
    expect(config('innertia.backups.retention_days'))->toBe(30);
});
