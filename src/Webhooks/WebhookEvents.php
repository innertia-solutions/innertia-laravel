<?php

namespace Innertia\Webhooks;

use Innertia\Webhooks\Models\Webhook;

/**
 * Catalog of webhook events a product emits (documentation, subscription UI and validation).
 * Register in a provider boot():
 *
 *   WebhookEvents::register('call.ended', 'Una llamada terminó', 'v1', ['id' => 'c_123', 'duration' => 42]);
 *   WebhookEvents::register('call.recording_ready', 'Grabación disponible', 'v1', [...], scope: 'recordings:read');
 *
 * `scope`: the subscription must have been granted that scope to receive the event's sensitive fields
 * (the DomainEvent decides what to omit in webhookPayload($scopes)).
 */
final class WebhookEvents
{
    /** @var array<string, WebhookEvent> */
    private static array $events = [];

    public static function register(string $key, string $description, string $version = 'v1', array $examplePayload = [], ?string $scope = null): WebhookEvent
    {
        return self::$events[$key] = new WebhookEvent($key, $description, $version, $examplePayload, $scope);
    }

    public static function get(string $key): ?WebhookEvent
    {
        return self::$events[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::$events[$key]);
    }

    /** @return array<string, WebhookEvent> sorted by key */
    public static function all(): array
    {
        $events = self::$events;
        ksort($events);

        return $events;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * A subscription pattern is valid when it is '*', a registered key, or a family wildcard ('call.*')
     * matching at least one registered key. With an empty catalog everything is accepted (legacy products).
     */
    public static function isValidPattern(string $pattern): bool
    {
        if ($pattern === '*' || self::$events === []) {
            return true;
        }

        foreach (array_keys(self::$events) as $key) {
            if (Webhook::patternMatches($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Forgets the catalog (tests). */
    public static function flush(): void
    {
        self::$events = [];
    }
}
