<?php

use Innertia\Telemetry\Support\PayloadSanitizer;

it('redacts sensitive keys recursively (case-insensitive substring)', function () {
    $s = new PayloadSanitizer(['password', 'token']);

    $out = $s->redact([
        'email'     => 'a@b.c',
        'Password'  => 'secret',
        'nested'    => ['api_token' => 'xyz', 'ok' => 1],
    ]);

    expect($out)->toBe([
        'email'  => 'a@b.c',
        'Password' => '[REDACTED]',
        'nested' => ['api_token' => '[REDACTED]', 'ok' => 1],
    ]);
});
