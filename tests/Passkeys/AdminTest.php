<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Passkeys\Models\Passkey;
use Innertia\Passkeys\UseCases\BeginPasskeyRegistration;
use Innertia\Passkeys\UseCases\FinishPasskeyRegistration;
use Innertia\Tests\Passkeys\Support\FakeAuthenticator;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    passkeysSetUp();
    Mail::fake();
    Route::middleware(\Innertia\Auth\Middleware\Authenticate::class)
        ->group(fn () => \Innertia\Backoffice\Routes::register(''));
});
afterEach(fn () => passkeysTearDown());

function pkAdminWithPasskeys($user, int $n): void
{
    foreach (range(1, $n) as $i) {
        $begin = (new BeginPasskeyRegistration($user))->execute();
        (new FinishPasskeyRegistration($user, $begin['key'], (new FakeAuthenticator())->register($begin['options']), "Equipo {$i}"))->execute();
    }
}

function pkAdminBearer($user): array
{
    // El guard jwt cachea el usuario entre requests dentro de un mismo test.
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer ' . app(\Innertia\Auth\Services\JwtService::class)->generateToken($user, ['context' => 'backoffice'])];
}

it('el admin ve las passkeys de un usuario sin la credencial', function () {
    $target = passkeyUser();
    pkAdminWithPasskeys($target, 2);

    $this->getJson("users/{$target->id}/passkeys", pkAdminBearer(passkeyUser(['name' => 'Admin'])))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.credential');
});

it('el admin revoca todas', function () {
    $target = passkeyUser();
    pkAdminWithPasskeys($target, 2);
    pkAdminWithPasskeys($other = passkeyUser(['name' => 'Otro']), 1);

    $this->deleteJson("users/{$target->id}/passkeys", [], pkAdminBearer(passkeyUser(['name' => 'Admin'])))
        ->assertOk()->assertJsonPath('revoked', 2);

    expect(Passkey::where('user_id', (string) $target->id)->count())->toBe(0)
        ->and(Passkey::where('user_id', (string) $other->id)->count())->toBe(1);
});

it('el comando revoca por correo', function () {
    $target = passkeyUser(['email' => 'owner@innertia.cl']);
    pkAdminWithPasskeys($target, 1);

    $this->artisan('passkeys:revoke', ['email' => 'owner@innertia.cl'])
        ->expectsOutputToContain('1 passkey')->assertSuccessful();

    expect(Passkey::count())->toBe(0);
});
