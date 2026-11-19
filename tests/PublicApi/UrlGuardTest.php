<?php

use Innertia\Webhooks\Security\UnsafeWebhookUrl;
use Innertia\Webhooks\Security\UrlGuard;

beforeEach(fn () => publicApiReset());

function guard(): UrlGuard
{
    return app(UrlGuard::class);
}

it('accepts a public https URL and returns the IP to pin', function () {
    expect(guard()->check('https://hooks.example.com/in'))
        ->toBe(['host' => 'hooks.example.com', 'port' => 443, 'ip' => '93.184.216.34']);
});

it('rejects non-https, credentials and unresolvable hosts', function (string $url, string $message) {
    fakeDns(['nowhere.example' => []]);

    expect(fn () => guard()->check($url))->toThrow(UnsafeWebhookUrl::class, $message);
})->with([
    ['http://hooks.example.com/in', 'https'],
    ['https://user:pass@hooks.example.com/in', 'credenciales'],
    ['https://nowhere.example/in', 'resolver'],
    ['not a url', 'válida'],
]);

it('blocks private, loopback, link-local, CGNAT and metadata addresses', function (string $url) {
    expect(fn () => guard()->check($url))->toThrow(UnsafeWebhookUrl::class);
})->with([
    'https://127.0.0.1/x',
    'https://10.1.2.3/x',
    'https://172.20.0.5/x',
    'https://192.168.1.10/x',
    'https://169.254.169.254/latest/meta-data',
    'https://100.64.0.1/x',
    'https://0.0.0.0/x',
    'https://[::1]/x',
    'https://[fd00::1]/x',
    'https://[fe80::1]/x',
    'https://[::ffff:127.0.0.1]/x',
    'https://localhost/x',
    'https://metadata.google.internal/x',
]);

it('blocks a public-looking name that resolves to a private address (DNS rebinding)', function () {
    fakeDns(['evil.example.com' => ['93.184.216.34', '10.0.0.7']]);

    expect(fn () => guard()->check('https://evil.example.com/hook'))->toThrow(UnsafeWebhookUrl::class, 'privada');
});

it('allows private networks and http only when configured (local development)', function () {
    config()->set('innertia.webhooks.allow_private_networks', true);
    config()->set('innertia.webhooks.allow_http', true);

    expect(guard()->check('http://127.0.0.1:8080/x'))->toMatchArray(['ip' => '127.0.0.1', 'port' => 8080]);
});
