<?php

use Illuminate\Support\Facades\Mail;
use Innertia\Passkeys\Exceptions\PasskeyVerificationFailed;
use Innertia\Passkeys\Mail\PasskeyAddedMail;
use Innertia\Passkeys\Models\Passkey;
use Innertia\Passkeys\UseCases\BeginPasskeyRegistration;
use Innertia\Passkeys\UseCases\FinishPasskeyRegistration;
use Innertia\Tests\Passkeys\Support\FakeAuthenticator;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => passkeysSetUp());
afterEach(fn () => passkeysTearDown());

it('registra una passkey, la nombra y avisa por correo', function () {
    Mail::fake();
    $user = passkeyUser();
    $auth = new FakeAuthenticator();

    $begin = (new BeginPasskeyRegistration($user))->execute();
    expect($begin['options']['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($begin['options']['authenticatorSelection']['residentKey'])->toBe('required')
        ->and($begin['options']['rp']['id'])->toBe('localhost');

    $passkey = (new FinishPasskeyRegistration($user, $begin['key'], $auth->register($begin['options']), 'Mac de Ana'))->execute();

    expect($passkey)->toBeInstanceOf(Passkey::class)
        ->and($passkey->user_id)->toBe((string) $user->id)
        ->and($passkey->name)->toBe('Mac de Ana')
        ->and($passkey->credential_id)->toBe($auth->id());
    Mail::assertQueued(PasskeyAddedMail::class, fn ($m) => $m->hasTo($user->email));
});

it('excluye las credenciales que el usuario ya tiene', function () {
    Mail::fake();
    $user = passkeyUser();
    $auth = new FakeAuthenticator();
    $begin = (new BeginPasskeyRegistration($user))->execute();
    (new FinishPasskeyRegistration($user, $begin['key'], $auth->register($begin['options'])))->execute();

    $again = (new BeginPasskeyRegistration($user))->execute();

    expect(collect($again['options']['excludeCredentials'])->pluck('id')->all())->toBe([$auth->id()]);
});

it('usa un nombre por defecto cuando no viene', function () {
    Mail::fake();
    $user = passkeyUser();
    $begin = (new BeginPasskeyRegistration($user))->execute();

    $passkey = (new FinishPasskeyRegistration($user, $begin['key'], (new FakeAuthenticator())->register($begin['options'])))->execute();

    expect($passkey->name)->toBe('Passkey');
});

it('rechaza un origen no permitido', function () {
    $user = passkeyUser();
    $begin = (new BeginPasskeyRegistration($user))->execute();

    (new FinishPasskeyRegistration($user, $begin['key'], (new FakeAuthenticator())->register($begin['options'], 'https://evil.test')))->execute();
})->throws(PasskeyVerificationFailed::class);

it('no deja reutilizar el challenge', function () {
    Mail::fake();
    $user = passkeyUser();
    $begin = (new BeginPasskeyRegistration($user))->execute();
    (new FinishPasskeyRegistration($user, $begin['key'], (new FakeAuthenticator())->register($begin['options'])))->execute();

    (new FinishPasskeyRegistration($user, $begin['key'], (new FakeAuthenticator())->register($begin['options'])))->execute();
})->throws(PasskeyVerificationFailed::class);

it('no deja usar el challenge de otro usuario', function () {
    $ana = passkeyUser();
    $beto = passkeyUser(['name' => 'Beto']);
    $begin = (new BeginPasskeyRegistration($ana))->execute();

    (new FinishPasskeyRegistration($beto, $begin['key'], (new FakeAuthenticator())->register($begin['options'])))->execute();
})->throws(PasskeyVerificationFailed::class);

it('renderiza el correo de passkey agregada', function () {
    $html = (new PasskeyAddedMail(passkeyUser(), 'Mac'))->render();

    expect($html)->toContain('Mac');
});
