<?php

use Innertia\Auth\UseCases\Login;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\PlatformAccountException;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => passkeysSetUp());
afterEach(fn () => passkeysTearDown());

it('con autenticación fuerte salta OTP y 2FA', function () {
    config()->set('innertia.auth.otp.enabled', true);
    config()->set('innertia.auth.2fa.enabled', true);
    $user = passkeyUser(['two_factor_enabled' => true]);

    $result = Login::issueFor($user, 'backoffice', strongAuth: true);

    expect($result)->toHaveKey('token')->and($result['user']->id)->toBe($user->id);
});

it('sin autenticación fuerte sigue pidiendo OTP', function () {
    \Illuminate\Support\Facades\Mail::fake();
    config()->set('innertia.auth.otp.enabled', true);
    $user = passkeyUser();

    expect(Login::issueFor($user, 'backoffice'))->toHaveKey('requires_otp', true);
});

it('mantiene el cambio forzado de contraseña aun con autenticación fuerte', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $user = passkeyUser(['force_password_change' => true]);

    expect(Login::issueFor($user, 'backoffice', strongAuth: true))->toHaveKey('requires_password_change', true);
});

it('rechaza un contexto sin acceso', function () {
    Login::issueFor(passkeyUser(['contexts' => 'portal']), 'backoffice', strongAuth: true);
})->throws(ForbiddenException::class);

it('rechaza cuentas de plataforma con identidad separada', function () {
    config()->set('innertia.platform.separate_identity', true);

    Login::issueFor(passkeyUser(['is_platform_admin' => true]), 'backoffice', strongAuth: true);
})->throws(PlatformAccountException::class);
