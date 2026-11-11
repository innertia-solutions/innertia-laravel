<?php

use Innertia\Passkeys\Exceptions\PasskeyVerificationFailed;
use Innertia\Passkeys\Support\Webauthn;
use Innertia\Tests\Passkeys\Support\FakeAuthenticator;

beforeEach(function () {
    config(['innertia.passkeys.rp_id' => 'localhost', 'innertia.passkeys.origins' => ['http://localhost']]);
});

it('hace un ciclo completo de registro e ingreso', function () {
    $auth = new FakeAuthenticator();

    $creation = Webauthn::creationOptions(random_bytes(16), 'a@b.cl', 'A B', []);
    $registered = Webauthn::verifyRegistration($creation, $auth->register($creation));

    expect($registered['credential_id'])->toBe($auth->id());

    $request = Webauthn::requestOptions();
    $assertion = $auth->assert($request, counter: 1);

    expect(Webauthn::credentialIdOf($assertion))->toBe($auth->id());
    expect(Webauthn::verifyAssertion($registered['credential'], $request, $assertion))->toBeString();
});

it('rechaza un origen no permitido', function () {
    $auth = new FakeAuthenticator();
    $creation = Webauthn::creationOptions(random_bytes(16), 'a@b.cl', 'A B', []);

    Webauthn::verifyRegistration($creation, $auth->register($creation, 'http://evil.test'));
})->throws(PasskeyVerificationFailed::class);

it('rechaza una aserción sin verificación de usuario (UV)', function () {
    $auth = new FakeAuthenticator();
    $creation = Webauthn::creationOptions(random_bytes(16), 'a@b.cl', 'A B', []);
    $registered = Webauthn::verifyRegistration($creation, $auth->register($creation));

    $request = Webauthn::requestOptions();
    Webauthn::verifyAssertion($registered['credential'], $request, $auth->assert($request, uv: false, counter: 1));
})->throws(PasskeyVerificationFailed::class);

it('rechaza una regresión del contador de firmas', function () {
    $auth = new FakeAuthenticator();
    $creation = Webauthn::creationOptions(random_bytes(16), 'a@b.cl', 'A B', []);
    $registered = Webauthn::verifyRegistration($creation, $auth->register($creation));

    $request = Webauthn::requestOptions();
    $updated = Webauthn::verifyAssertion($registered['credential'], $request, $auth->assert($request, counter: 5));

    $request2 = Webauthn::requestOptions();
    Webauthn::verifyAssertion($updated, $request2, $auth->assert($request2, counter: 3));
})->throws(PasskeyVerificationFailed::class);
