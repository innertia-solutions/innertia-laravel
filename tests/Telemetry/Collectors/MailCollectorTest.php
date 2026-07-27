<?php

use Innertia\Telemetry\Collectors\MailCollector;
use Innertia\Telemetry\TelemetryCollector;
use Symfony\Component\Mime\Email;

it('records a mail event with recipients and subject', function () {
    $collector = new TelemetryCollector('my-app', 'sess-1', 'acme', 'local');

    $email = (new Email())
        ->from('sys@app.test')
        ->to('user@app.test')
        ->subject('Welcome')
        ->text('hi');

    MailCollector::handle($collector, $email);

    $event = $collector->batch()[0];
    expect($event->type)->toBe('mail')
        ->and($event->payload['subject'])->toBe('Welcome')
        ->and($event->payload['to'])->toContain('user@app.test');
});
