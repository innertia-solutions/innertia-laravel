<?php

use Illuminate\Support\Facades\Mail;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Passkeys\Exceptions\PasskeyVerificationFailed;
use Innertia\Passkeys\Models\Passkey;
use Innertia\Passkeys\UseCases\BeginPasskeyLogin;
use Innertia\Passkeys\UseCases\BeginPasskeyRegistration;
use Innertia\Passkeys\UseCases\FinishPasskeyRegistration;
use Innertia\Passkeys\UseCases\PasskeyLogin;
use Innertia\Passkeys\UseCases\PlatformPasskeyLogin;
use Innertia\Tests\Passkeys\Support\FakeAuthenticator;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    passkeysSetUp();
    Mail::fake();
});
afterEach(fn () => passkeysTearDown());

function pkEnrolled(array $attrs = []): array
{
    $user = passkeyUser($attrs);
    $auth = new FakeAuthenticator();
    $begin = (new BeginPasskeyRegistration($user))->execute();
    (new FinishPasskeyRegistration($user, $begin['key'], $auth->register($begin['options'])))->execute();

    return [$user, $auth];
}

function pkLoginWith(FakeAuthenticator $auth, array $contexts, array $assertOpts = []): array
{
    $begin = (new BeginPasskeyLogin())->execute();
    expect($begin['options']['allowCredentials'] ?? [])->toBe([])
        ->and($begin['options']['userVerification'])->toBe('required');

    return (new PasskeyLogin($begin['key'], $auth->assert($begin['options'], ...$assertOpts), $contexts))->execute();
}

it('entra con passkey al primer contexto con acceso y actualiza el último uso', function () {
    [$user, $auth] = pkEnrolled(['contexts' => 'portal']);

    $result = pkLoginWith($auth, ['backoffice', 'portal']);

    expect($result)->toHaveKey('token')
        ->and($result['context'])->toBe('portal')
        ->and($result['user']->id)->toBe($user->id)
        ->and(Passkey::first()->last_used_at)->not->toBeNull();
});

it('salta el OTP aunque esté activo', function () {
    config()->set('innertia.auth.otp.enabled', true);
    [, $auth] = pkEnrolled();

    expect(pkLoginWith($auth, ['backoffice']))->toHaveKey('token');
});

it('sin contexto con acceso responde 403', function () {
    [, $auth] = pkEnrolled(['contexts' => 'portal']);

    pkLoginWith($auth, ['backoffice']);
})->throws(ForbiddenException::class, 'Esta cuenta no tiene acceso');

it('rechaza una passkey desconocida', function () {
    pkLoginWith(new FakeAuthenticator(), ['backoffice']);
})->throws(PasskeyVerificationFailed::class);

it('rechaza un ingreso sin verificación de usuario', function () {
    [, $auth] = pkEnrolled();

    pkLoginWith($auth, ['backoffice'], [null, false]);
})->throws(PasskeyVerificationFailed::class);

it('rechaza un contador que retrocede', function () {
    [, $auth] = pkEnrolled();
    pkLoginWith($auth, ['backoffice'], [null, true, 5]);

    pkLoginWith($auth, ['backoffice'], [null, true, 3]);
})->throws(PasskeyVerificationFailed::class);

it('rechaza un origen no permitido', function () {
    [, $auth] = pkEnrolled();

    pkLoginWith($auth, ['backoffice'], ['https://evil.test']);
})->throws(PasskeyVerificationFailed::class);

it('no deja reutilizar el challenge de login', function () {
    [, $auth] = pkEnrolled();
    $begin = (new BeginPasskeyLogin())->execute();
    $assertion = $auth->assert($begin['options']);
    (new PasskeyLogin($begin['key'], $assertion, ['backoffice']))->execute();

    (new PasskeyLogin($begin['key'], $assertion, ['backoffice']))->execute();
})->throws(PasskeyVerificationFailed::class);

it('plataforma: entra solo con cuenta de plataforma y emite token platform', function () {
    [$admin, $auth] = pkEnrolled(['is_platform_admin' => true]);
    $begin = (new BeginPasskeyLogin())->execute();

    $result = (new PlatformPasskeyLogin($begin['key'], $auth->assert($begin['options'])))->execute();

    expect($result)->toHaveKey('token')->and($result['user']->id)->toBe($admin->id);
    $claims = app(\Innertia\Auth\Services\JwtService::class)->decode($result['token']);
    expect((bool) ($claims->platform ?? false))->toBeTrue();
});

it('plataforma: una cuenta común recibe 403', function () {
    [, $auth] = pkEnrolled();
    $begin = (new BeginPasskeyLogin())->execute();

    (new PlatformPasskeyLogin($begin['key'], $auth->assert($begin['options'])))->execute();
})->throws(ForbiddenException::class);
