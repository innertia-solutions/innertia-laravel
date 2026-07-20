<?php

use Illuminate\Http\Request;
use Innertia\Http\Middleware\ForceJsonResponse;

function _accepted(string $uri, ?string $accept = null): ?string
{
    $server = $accept !== null ? ['HTTP_ACCEPT' => $accept] : [];
    $req = Request::create($uri, 'GET', server: $server);
    (new ForceJsonResponse)->handle($req, fn ($r) => response('ok'));

    return $req->headers->get('Accept');
}

it('fuerza Accept application/json en rutas de API normales', function () {
    expect(_accepted('/platform/gyms', 'text/html'))->toBe('application/json');
    expect(_accepted('/platform/gyms'))->toBe('application/json');
});

it('NO fuerza JSON en la ruta de view de archivos (link compartible)', function () {
    expect(_accepted('/files/abc-123/view', 'text/html'))->toContain('text/html');
});

it('NO fuerza JSON en la ruta de download de archivos', function () {
    expect(_accepted('/files/abc-123/download', 'text/html'))->toContain('text/html');
});
