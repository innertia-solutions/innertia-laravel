<?php

use Innertia\Files\Providers\ProviderRegistry;
use Innertia\Files\Providers\FileProvider;

it('resuelve el driver configurado cuando enabled', function () {
    config()->set('innertia.files.providers.google_drive.enabled', true);
    expect(app(ProviderRegistry::class)->for('google_drive'))->toBeInstanceOf(FileProvider::class);
});

it('lanza si el proveedor está disabled', function () {
    config()->set('innertia.files.providers.google_drive.enabled', false);
    expect(fn () => app(ProviderRegistry::class)->for('google_drive'))->toThrow(\RuntimeException::class);
});
