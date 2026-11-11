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

it('con tenancy activa y tenant en curso, el interruptor se guarda y lee a nivel plataforma', function () {
    config()->set('innertia.mode', 'saas');
    app()->forgetInstance(\Innertia\InnertiaManager::class);
    \Illuminate\Support\Facades\Facade::clearResolvedInstance(\Innertia\InnertiaManager::class);
    \Illuminate\Database\Eloquent\Model::clearBootedModels();

    \Illuminate\Support\Facades\Schema::dropIfExists('settings');
    \Illuminate\Support\Facades\Schema::create('settings', function ($t) {
        $t->id();
        $t->string('tenant_id')->nullable();
        $t->string('key');
        $t->string('value_type')->default('string');
        $t->text('value')->nullable();
        $t->boolean('is_encrypted')->default(false);
        $t->timestamps();
    });
    PasskeySettings::flushCache();

    app(\Innertia\Saas\TenantContext::class)->set((new \Innertia\Saas\Models\Tenant())->forceFill(['id' => 7]));

    PasskeySettings::save(['enabled' => false]);

    expect(\Illuminate\Support\Facades\DB::table('settings')->where('key', 'passkeys.enabled')->value('tenant_id'))->toBeNull()
        ->and(PasskeySettings::enabled())->toBeFalse();

    app(\Innertia\Saas\TenantContext::class)->clear();
    \Illuminate\Database\Eloquent\Model::clearBootedModels();
    config()->set('innertia.mode', 'app');
    app()->forgetInstance(\Innertia\InnertiaManager::class);
    \Illuminate\Support\Facades\Facade::clearResolvedInstance(\Innertia\InnertiaManager::class);
});

it('los booleanos resueltos se cachean y save() los invalida', function () {
    passkeysSettingsTable();
    expect(PasskeySettings::enabled())->toBeTrue()
        ->and(\Illuminate\Support\Facades\Cache::get('innertia_passkeys_enabled'))->toBeTrue();

    // Cambio directo en BD, sin pasar por save(): la caché manda hasta que se invalida.
    \Illuminate\Support\Facades\DB::table('settings')->insert(['key' => 'passkeys.enabled', 'value_type' => 'boolean', 'value' => '0']);
    expect(PasskeySettings::enabled())->toBeTrue();

    PasskeySettings::save(['enabled' => false]);
    expect(\Illuminate\Support\Facades\Cache::has('innertia_passkeys_enabled'))->toBeFalse()
        ->and(PasskeySettings::enabled())->toBeFalse();
});

it('sin tabla passkeys el módulo queda apagado y vuelve al migrar', function () {
    passkeysSettingsTable();
    AuthRoutesForPasskeyTableTest();

    \Illuminate\Support\Facades\Schema::dropIfExists('passkeys');
    PasskeySettings::flushCache();

    expect(PasskeyConfig::enabled())->toBeFalse();
    $this->getJson('auth/passkey/status')->assertOk()->assertExactJson(['enabled' => false]);
    $this->postJson('auth/passkey/login/options')->assertNotFound();
    $this->postJson('auth/passkey/login')->assertNotFound();

    $target = passkeyUser();
    $headers = (function ($u) {
        app('auth')->forgetGuards();
        return ['Authorization' => 'Bearer ' . app(\Innertia\Auth\Services\JwtService::class)->generateToken($u, ['context' => 'backoffice'])];
    })(passkeyUser(['name' => 'Admin']));
    $this->getJson("users/{$target->id}/passkeys", $headers)->assertOk()->assertExactJson(['data' => []]);
    $this->deleteJson("users/{$target->id}/passkeys", [], $headers)->assertOk()->assertExactJson(['revoked' => 0]);

    $this->artisan('passkeys:revoke', ['email' => $target->email])->assertFailed();

    // Al migrar (sin flush de caché) se enciende.
    \Illuminate\Support\Facades\Schema::create('passkeys', fn ($t) => $t->uuid('id')->primary());
    expect(PasskeyConfig::enabled())->toBeTrue();
    \Illuminate\Support\Facades\Schema::dropIfExists('passkeys');
});

function AuthRoutesForPasskeyTableTest(): void
{
    \Innertia\Auth\Routes::publicRoutes('auth');
    Route::middleware(\Innertia\Auth\Middleware\Authenticate::class)
        ->group(fn () => \Innertia\Backoffice\Routes::register(''));
}
