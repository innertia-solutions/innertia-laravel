<?php

use Innertia\Telemetry\Cases\CaseSnapshot;

// In the Testbench environment, base_path() resolves to the testbench-core
// laravel stub, not the package root. We patch it to the package root so that
// isInApp() correctly identifies frames from src/ and tests/ as in-app.
beforeEach(function () {
    app()->setBasePath(dirname(__DIR__, 2)); // innertia-laravel/
});

it('arma stack con frame in-app + código fuente y no lanza', function () {
    $e = new \RuntimeException('boom snapshot');
    $snap = CaseSnapshot::build($e);

    expect($snap)->toHaveKeys(['message', 'exception_class', 'stack', 'previous', 'request', 'breadcrumbs', 'captured_at']);
    expect($snap['message'])->toBe('boom snapshot');
    expect($snap['exception_class'])->toBe(\RuntimeException::class);
    expect($snap['stack'])->toBeArray()->not->toBeEmpty();

    $top = collect($snap['stack'])->firstWhere('in_app', true);
    expect($top)->not->toBeNull();
    expect($top)->toHaveKeys(['function', 'file', 'line', 'in_app', 'context']);
    expect($top['in_app'])->toBeTrue();
    expect($top['context'])->toBeArray();
    expect(collect($top['context'])->firstWhere('error', true))->not->toBeNull();
});

it('incluye la cadena de excepciones previas', function () {
    $e = new \RuntimeException('outer', 0, new \LogicException('inner'));
    $snap = CaseSnapshot::build($e);
    expect($snap['previous'])->toHaveCount(1);
    expect($snap['previous'][0]['class'])->toBe(\LogicException::class);
    expect($snap['previous'][0]['message'])->toBe('inner');
});
