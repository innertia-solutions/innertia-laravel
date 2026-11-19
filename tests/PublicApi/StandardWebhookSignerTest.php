<?php

use Innertia\Webhooks\Signing\StandardWebhookSigner;

// Official test vector of the Standard Webhooks spec (also used by the svix libraries).
const SW_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
const SW_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
const SW_TS = 1614265330;
const SW_BODY = '{"test": 2432232314}';
const SW_SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

it('signs the known Standard Webhooks vector', function () {
    expect(StandardWebhookSigner::sign(SW_SECRET, SW_ID, SW_TS, SW_BODY))->toBe(SW_SIGNATURE);
});

it('verifies the known vector and rejects tampering or stale timestamps', function () {
    expect(StandardWebhookSigner::verify(SW_SECRET, SW_ID, SW_TS, SW_BODY, SW_SIGNATURE, now: SW_TS + 10))->toBeTrue()
        ->and(StandardWebhookSigner::verify(SW_SECRET, SW_ID, SW_TS, '{"test": 1}', SW_SIGNATURE, now: SW_TS))->toBeFalse()
        ->and(StandardWebhookSigner::verify(SW_SECRET, SW_ID, SW_TS, SW_BODY, SW_SIGNATURE, now: SW_TS + 3600))->toBeFalse();
});

it('signs with several secrets during a rotation (space separated)', function () {
    $new = StandardWebhookSigner::generateSecret();
    $header = StandardWebhookSigner::header([$new, SW_SECRET], SW_ID, SW_TS, SW_BODY);

    expect(explode(' ', $header))->toHaveCount(2)
        ->and(StandardWebhookSigner::verify($new, SW_ID, SW_TS, SW_BODY, $header, now: SW_TS))->toBeTrue()
        ->and(StandardWebhookSigner::verify(SW_SECRET, SW_ID, SW_TS, SW_BODY, $header, now: SW_TS))->toBeTrue();
});

it('generates whsec_ secrets with a 24-byte key', function () {
    $secret = StandardWebhookSigner::generateSecret();

    expect($secret)->toStartWith('whsec_')
        ->and(strlen(base64_decode(substr($secret, 6), true)))->toBe(24)
        ->and(StandardWebhookSigner::hint($secret))->toBe('whsec_…' . substr($secret, -4));
});
