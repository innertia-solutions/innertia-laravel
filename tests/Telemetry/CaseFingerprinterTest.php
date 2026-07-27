<?php

use Innertia\Telemetry\Cases\CaseFingerprinter;

it('produces a stable fingerprint ignoring volatile numbers/ids in the message', function () {
    $fp = new CaseFingerprinter();

    $a = $fp->for(\RuntimeException::class, '/app/Foo.php', 42, 'User 123 not found');
    $b = $fp->for(\RuntimeException::class, '/app/Foo.php', 42, 'User 999 not found');

    expect($a)->toBe($b)->and($a)->toBeString()->toHaveLength(64);
});

it('differs when class/file/line differ', function () {
    $fp = new CaseFingerprinter();

    expect($fp->for(\RuntimeException::class, '/app/Foo.php', 42, 'x'))
        ->not->toBe($fp->for(\LogicException::class, '/app/Foo.php', 42, 'x'));
});

it('normalizes uuids to a single placeholder', function () {
    $fp = new CaseFingerprinter();

    expect($fp->normalizeMessage('Model 550e8400-e29b-41d4-a716-446655440000 missing'))
        ->toBe('Model {uuid} missing');

    // Dos uuids distintos agrupan al mismo fingerprint.
    expect($fp->for(\RuntimeException::class, '/f.php', 1, 'Model 550e8400-e29b-41d4-a716-446655440000 missing'))
        ->toBe($fp->for(\RuntimeException::class, '/f.php', 1, 'Model 11111111-2222-3333-4444-555555555555 missing'));
});

it('normalizes quoted strings by matching quote types', function () {
    $fp = new CaseFingerprinter();

    expect($fp->normalizeMessage('Column "email" not found'))->toBe('Column {str} not found');
    expect($fp->normalizeMessage("Key 'id' missing"))->toBe('Key {str} missing');
});
