<?php

use Innertia\Exceptions\InnertiaException;
use Innertia\Exceptions\PlatformAccountException;

it('mapea a 409 con errorKey platform_account', function () {
    $e = new PlatformAccountException();

    expect($e)->toBeInstanceOf(InnertiaException::class);
    expect($e->getStatusCode())->toBe(409);
    expect($e->getErrorKey())->toBe('platform_account');
});

it('acepta un mensaje personalizado', function () {
    $e = new PlatformAccountException('Usa el acceso de plataforma.');

    expect($e->getMessage())->toBe('Usa el acceso de plataforma.');
});
