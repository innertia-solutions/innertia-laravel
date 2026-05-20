<?php

use Innertia\Devtools\Tinker\TinkerEvaluator;
use Innertia\Devtools\Tinker\TinkerSession;

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['innertia.devtools.tinker.cache_store' => 'array']);
    config(['innertia.devtools.tinker.session_ttl' => 1800]);
});

it('captures echo output', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $result = $evaluator->evaluate($session, 'echo "hello world";');

    expect($result['output'])->toBe('hello world')
        ->and($result['error'])->toBeNull();
});

it('captures the return value', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $result = $evaluator->evaluate($session, '$x = 2 + 2;');

    expect($result['error'])->toBeNull();
});

it('persists variables between evals', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $evaluator->evaluate($session, '$counter = 10;');
    $result = $evaluator->evaluate($session, 'echo $counter;');

    expect($result['output'])->toBe('10')
        ->and($result['error'])->toBeNull();
});

it('captures exceptions as error string', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $result = $evaluator->evaluate($session, 'throw new \RuntimeException("oops");');

    expect($result['error'])->toContain('RuntimeException: oops')
        ->and($result['output'])->toBe('');
});

it('captures parse errors as error string', function () {
    $session   = TinkerSession::create();
    $evaluator = new TinkerEvaluator();

    $result = $evaluator->evaluate($session, '$x = ;');

    expect($result['error'])->not->toBeNull();
});
