<?php

use Innertia\Exceptions\InnertiaExceptionHandler;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function _render(\Throwable $e): \Illuminate\Http\JsonResponse
{
    $m = new ReflectionMethod(InnertiaExceptionHandler::class, 'toJson');
    $m->setAccessible(true);

    return $m->invoke(null, $e);
}

it('mapea abort(403) HttpException a 403 (no 500 server_error)', function () {
    $res  = _render(new HttpException(403, 'Invalid or expired signature.'));
    $data = $res->getData(true);

    expect($res->getStatusCode())->toBe(403);
    expect($data['error'])->not->toBe('server_error');
    expect($data['message'])->toBe('Invalid or expired signature.');
});

it('mapea abort(401) a 401', function () {
    expect(_render(new HttpException(401, 'Authentication required.'))->getStatusCode())->toBe(401);
});

it('mapea abort(404) a 404', function () {
    expect(_render(new HttpException(404, 'File not found.'))->getStatusCode())->toBe(404);
});

it('mapea AccessDeniedHttpException a 403 forbidden', function () {
    $res = _render(new AccessDeniedHttpException('Nope.'));
    expect($res->getStatusCode())->toBe(403);
    expect($res->getData(true)['error'])->toBe('forbidden');
});

it('un error inesperado sigue siendo 500 server_error', function () {
    $res = _render(new \RuntimeException('boom'));
    expect($res->getStatusCode())->toBe(500);
    expect($res->getData(true)['error'])->toBe('server_error');
});
