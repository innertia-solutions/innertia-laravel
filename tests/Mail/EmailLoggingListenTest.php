<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Innertia\Mail\EmailLogging;
use Innertia\Mail\Listeners\LogSentEmail;
use Innertia\Mail\Listeners\MarkFailedEmail;

function countListeners(string $event, string $listener): int
{
    return collect(app('events')->getRawListeners()[$event] ?? [])
        ->filter(fn ($l) => $l === $listener)
        ->count();
}

it('listen() is idempotent on the same dispatcher', function () {
    EmailLogging::listen();
    EmailLogging::listen();
    EmailLogging::listen();

    expect(countListeners(MessageSent::class, LogSentEmail::class))->toBe(1)
        ->and(countListeners(JobFailed::class, MarkFailedEmail::class))->toBe(1);
});

it('a fresh application gets the listeners again without reset()', function () {
    EmailLogging::listen();
    expect(countListeners(MessageSent::class, LogSentEmail::class))->toBe(1);

    $this->refreshApplication();
    expect(countListeners(MessageSent::class, LogSentEmail::class))->toBe(0);

    EmailLogging::listen();
    expect(countListeners(MessageSent::class, LogSentEmail::class))->toBe(1);
});
