<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Innertia\Passkeys\Support\PasskeyConfig;

uses(RefreshDatabase::class);

it('deriva rp_id, rp_name y origins del frontend_url', function () {
    config()->set('app.frontend_url', 'https://app.pomely.cl/');
    config()->set('app.name', 'pomely');

    expect(PasskeyConfig::rpId())->toBe('app.pomely.cl')
        ->and(PasskeyConfig::rpName())->toBe('pomely')
        ->and(PasskeyConfig::origins())->toBe(['https://app.pomely.cl']);
});

it('respeta los valores explícitos', function () {
    config()->set('innertia.passkeys.rp_id', 'pomely.cl');
    config()->set('innertia.passkeys.rp_name', 'Pomely');
    config()->set('innertia.passkeys.origins', ['https://a.pomely.cl', 'https://b.pomely.cl']);

    expect(PasskeyConfig::rpId())->toBe('pomely.cl')
        ->and(PasskeyConfig::rpName())->toBe('Pomely')
        ->and(PasskeyConfig::origins())->toBe(['https://a.pomely.cl', 'https://b.pomely.cl']);
});

it('incluye el puerto en el origen de localhost', function () {
    config()->set('app.frontend_url', 'http://localhost:4400');

    expect(PasskeyConfig::rpId())->toBe('localhost')
        ->and(PasskeyConfig::origins())->toBe(['http://localhost:4400']);
});

it('crea la tabla passkeys cuando está habilitado', function () {
    expect(Schema::hasTable('passkeys'))->toBeTrue();
});
