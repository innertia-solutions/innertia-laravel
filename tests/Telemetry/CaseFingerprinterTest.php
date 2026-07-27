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
