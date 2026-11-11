<?php

use Illuminate\Support\Facades\Route;
use Innertia\Passkeys\Routes as PasskeyRoutes;
use Innertia\Passkeys\Support\PasskeyConfig;
use Innertia\Passkeys\Support\PasskeySettings;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    passkeysSetUp();
    Route::prefix('platform')->group(fn () => PasskeyRoutes::platformSettings(''));
});
afterEach(fn () => passkeysTearDown());

it('sin tabla settings rige el default: encendido y sin subdominios', function () {
    \Illuminate\Support\Facades\Schema::dropIfExists('settings');

    expect(PasskeySettings::enabled())->toBeTrue()
        ->and(PasskeySettings::allowSubdomains())->toBeFalse()
        ->and(PasskeyConfig::enabled())->toBeTrue();
});

it('guarda y lee, y la caché no queda vieja', function () {
    passkeysSettingsTable();
    expect(PasskeySettings::enabled())->toBeTrue();

    PasskeySettings::save(['enabled' => false, 'allow_subdomains' => true]);
    expect(PasskeySettings::all())->toBe(['enabled' => false, 'allow_subdomains' => true])
        ->and(PasskeyConfig::allowSubdomains())->toBeTrue();

    PasskeySettings::save(['enabled' => true]);
    expect(PasskeySettings::enabled())->toBeTrue()
        ->and(PasskeySettings::allowSubdomains())->toBeTrue();
});

it('GET settings devuelve enabled, allow_subdomains, rp_id y origins', function () {
    passkeysSettingsTable();

    $this->getJson('platform/passkeys/settings')->assertOk()->assertExactJson([
        'enabled' => true, 'allow_subdomains' => false,
        'rp_id' => 'localhost', 'origins' => ['http://localhost'],
    ]);
});

it('PUT settings valida y persiste', function () {
    passkeysSettingsTable();

    $this->putJson('platform/passkeys/settings', [])->assertStatus(422);
    $this->putJson('platform/passkeys/settings', ['enabled' => 'x'])->assertStatus(422);

    $this->putJson('platform/passkeys/settings', ['enabled' => false, 'allow_subdomains' => true])
        ->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('allow_subdomains', true);

    expect(PasskeySettings::enabled())->toBeFalse();
});

it('/status expone features.passkeys', function () {
    passkeysSettingsTable();
    Route::get('status', [\Innertia\App\Http\Controllers\StatusController::class, 'status']);

    $this->getJson('status')->assertOk()->assertJsonPath('features.passkeys', true);

    PasskeySettings::save(['enabled' => false]);
    $this->getJson('status')->assertOk()->assertJsonPath('features.passkeys', false);
});
