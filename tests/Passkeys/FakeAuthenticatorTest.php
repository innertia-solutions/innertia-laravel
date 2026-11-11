<?php

use Innertia\Tests\Passkeys\Support\FakeAuthenticator;
use ParagonIE\ConstantTime\Base64UrlSafe;

it('arma respuestas de registro y assertion con el challenge recibido', function () {
    $auth = new FakeAuthenticator();
    $opts = ['challenge' => 'abc', 'user' => ['id' => Base64UrlSafe::encodeUnpadded('42')]];

    $reg = $auth->register($opts);
    $cd  = json_decode(Base64UrlSafe::decodeNoPadding($reg['response']['clientDataJSON']), true);
    expect($cd['type'])->toBe('webauthn.create')->and($cd['challenge'])->toBe('abc');

    $as = $auth->assert(['challenge' => 'xyz']);
    expect(Base64UrlSafe::decodeNoPadding($as['response']['userHandle']))->toBe('42');
});
