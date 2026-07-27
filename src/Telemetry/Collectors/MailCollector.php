<?php

namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;
use Symfony\Component\Mime\Email;

class MailCollector
{
    public static function handle(TelemetryCollector $collector, Email $message): void
    {
        $collector->record(new TelemetryEvent(
            type: 'mail',
            payload: [
                'subject' => $message->getSubject(),
                'to'      => array_map(fn ($a) => $a->getAddress(), $message->getTo()),
                'cc'      => array_map(fn ($a) => $a->getAddress(), $message->getCc()),
                'from'    => array_map(fn ($a) => $a->getAddress(), $message->getFrom()),
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => auth()->id(),
                'env'     => app()->environment(),
            ],
        ));
    }
}
