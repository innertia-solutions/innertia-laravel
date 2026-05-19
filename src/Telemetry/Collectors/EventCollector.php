<?php

namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

class EventCollector
{
    private const IGNORED_PREFIXES = [
        'Illuminate\\',
        'Laravel\\',
        'eloquent.',
        'bootstrapped:',
        'Innertia\\Telemetry\\',
    ];

    public static function handle(TelemetryCollector $collector, string $eventName, array $payload): void
    {
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($eventName, $prefix)) return;
        }

        $collector->record(new TelemetryEvent(
            type: 'event',
            payload: [
                'event'   => $eventName,
                'payload' => self::safeSerialize($payload),
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => request()?->method() . ' ' . request()?->path(),
                'env'     => self::getEnvironment(),
                'source'  => request()?->header('X-Innertia-Source', 'cli'),
            ],
        ));
    }

    private static function safeSerialize(array $payload): array
    {
        try {
            return json_decode(json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [];
        } catch (\Throwable) {
            return ['_unserializable' => true];
        }
    }

    private static function getEnvironment(): string
    {
        try {
            return app()->environment();
        } catch (\Throwable) {
            return 'testing';
        }
    }
}
