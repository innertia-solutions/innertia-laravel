<?php

it('exposes the v2 telemetry config keys and drops olimpo keys', function () {
    $cfg = config('innertia.telemetry');

    expect($cfg)->toHaveKeys([
        'enabled', 'app_name', 'queue', 'retention_days',
        'case_retention_days', 'support_email',
        'capture', 'except', 'mcp', 'presence', 'redact',
    ]);

    expect($cfg)->not->toHaveKey('mode');
    expect($cfg)->not->toHaveKey('olimpo_url');
    expect($cfg)->not->toHaveKey('olimpo_key');

    expect($cfg['mcp'])->toHaveKeys(['enabled', 'web']);
    expect($cfg['presence'])->toHaveKeys(['enabled', 'ttl']);
    expect($cfg['redact'])->toBeArray()->toContain('password');
    // capture.mail existe como flag reservado (el MailCollector se cablea en una task posterior).
    expect($cfg['capture'])->toHaveKey('mail');
});

it('trae el canal operational por defecto', function () {
    $channels = collect(config('innertia.telemetry.channels'));

    expect($channels->pluck('key'))->toContain('operational');
});

it('does not activate telemetry when disabled, even in local/testing', function () {
    config()->set('innertia.telemetry.enabled', false);

    $provider = new \Innertia\Telemetry\TelemetryServiceProvider(app());
    $ref = new ReflectionMethod($provider, 'shouldActivate');
    $ref->setAccessible(true);

    expect($ref->invoke($provider))->toBeFalse();
});

it('activates telemetry in local/testing when enabled', function () {
    config()->set('innertia.telemetry.enabled', true);

    $provider = new \Innertia\Telemetry\TelemetryServiceProvider(app());
    $ref = new ReflectionMethod($provider, 'shouldActivate');
    $ref->setAccessible(true);

    expect($ref->invoke($provider))->toBeTrue();
});
