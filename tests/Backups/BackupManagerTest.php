<?php

use Innertia\Backups\BackupManager;
use Innertia\Backups\Drivers\AppBackupDriver;
use Innertia\Backups\Exceptions\UnsupportedBackupModeException;

it('resolves the app driver by default', function () {
    config()->set('innertia.backups.mode', 'app');
    expect(app(BackupManager::class)->driver())->toBeInstanceOf(AppBackupDriver::class);
});

it('throws for deferred saas/open modes', function () {
    config()->set('innertia.backups.mode', 'saas');
    expect(fn () => app(BackupManager::class)->driver())
        ->toThrow(UnsupportedBackupModeException::class);

    config()->set('innertia.backups.mode', 'open');
    expect(fn () => app(BackupManager::class)->driver())
        ->toThrow(UnsupportedBackupModeException::class);
});
