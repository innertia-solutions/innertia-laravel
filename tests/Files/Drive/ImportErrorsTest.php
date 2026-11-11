<?php

use Illuminate\Contracts\Cache\LockTimeoutException;
use Innertia\Files\Directories\Exceptions\MaxDepthExceededException;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\FolderNameExhaustedException;
use Innertia\Files\Drive\Import\ImportErrors;

it('exposes only Drive and known domain messages', function () {
    expect(ImportErrors::publicMessage(new DriveException('Google caído', 'connection')))->toBe('Google caído');
    expect(ImportErrors::publicMessage(new DriveFileTooLargeException(10)))->toBe((new DriveFileTooLargeException(10))->getMessage());
    expect(ImportErrors::publicMessage(new MaxDepthExceededException('Profundidad máxima')))->toBe('Profundidad máxima');
    expect(ImportErrors::publicMessage(new FolderNameExhaustedException('X')))->toContain('demasiados nombres repetidos');
    expect(ImportErrors::publicMessage(new LockTimeoutException()))->toBe('Otro proceso está importando este elemento.');
});

it('hides any other error behind a generic text', function () {
    expect(ImportErrors::publicMessage(new \RuntimeException('SQLSTATE[HY000]: boom')))->toBe(ImportErrors::GENERIC);
    expect(ImportErrors::publicMessage(new \Error('Call to undefined method')))->toBe(ImportErrors::GENERIC);
    expect(ImportErrors::GENERIC)->toBe('Error interno al importar el elemento (ver registro con el trace_id).');
});
