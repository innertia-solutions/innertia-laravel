<?php

use Innertia\Backups\Support\ProcessResult;

it('reports success and captures error output', function () {
    $ok = new ProcessResult(exitCode: 0, errorOutput: '');
    expect($ok->successful())->toBeTrue();
    expect($ok->errorOutput())->toBe('');

    $fail = new ProcessResult(exitCode: 1, errorOutput: 'pg_dump: connection refused');
    expect($fail->successful())->toBeFalse();
    expect($fail->errorOutput())->toBe('pg_dump: connection refused');
});
