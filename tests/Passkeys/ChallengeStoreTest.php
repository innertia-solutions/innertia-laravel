<?php

use Innertia\Passkeys\Exceptions\PasskeyVerificationFailed;
use Innertia\Passkeys\Support\ChallengeStore;

it('guarda y consume una sola vez', function () {
    $key = ChallengeStore::put('login', ['challenge' => 'c1']);

    expect(ChallengeStore::pull($key, 'login'))->toBe(['type' => 'login', 'challenge' => 'c1']);
    ChallengeStore::pull($key, 'login');
})->throws(PasskeyVerificationFailed::class);

it('rechaza un tipo distinto y lo consume igual', function () {
    $key = ChallengeStore::put('register', ['challenge' => 'c1']);

    expect(fn () => ChallengeStore::pull($key, 'login'))->toThrow(PasskeyVerificationFailed::class)
        ->and(fn () => ChallengeStore::pull($key, 'register'))->toThrow(PasskeyVerificationFailed::class);
});

it('la excepción responde 422 con mensaje genérico', function () {
    $response = (new PasskeyVerificationFailed('detalle interno'))->render();

    expect($response->status())->toBe(422)
        ->and($response->getData(true)['message'])->toBe('No pudimos verificar la passkey.');
});
