<?php

namespace Innertia\PublicApi;

use Innertia\ApiKeys\Models\ApiKey;

/**
 * Actor of a request authenticated with an integration key. While set, ActivityLogger records
 * metadata.actor = {type: integration, api_key_id, name, label} and EntityHistory uses the label as reason
 * ("Integración: <nombre>") when no user is authenticated.
 *
 *   ActsAsIntegration::current();          // ?ApiKey
 *   ActsAsIntegration::label();            // "Integración: ERP"
 *   ActsAsIntegration::run($key, fn () => (new RegisterInteraction(...))->execute());  // jobs/commands
 */
final class ActsAsIntegration
{
    private const BINDING = 'innertia.integration_actor';

    public static function set(?ApiKey $key): void
    {
        if ($key === null) {
            app()->forgetInstance(self::BINDING);

            return;
        }

        app()->instance(self::BINDING, $key);
    }

    public static function current(): ?ApiKey
    {
        return app()->bound(self::BINDING) ? app(self::BINDING) : null;
    }

    public static function label(): ?string
    {
        $key = self::current();

        return $key ? 'Integración: ' . $key->name : null;
    }

    /** Actor metadata for logs, or null when no integration is acting. */
    public static function actor(): ?array
    {
        $key = self::current();

        return $key ? ['type' => 'integration', 'api_key_id' => $key->id, 'name' => $key->name, 'label' => self::label()] : null;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(ApiKey $key, callable $callback): mixed
    {
        $previous = self::current();
        self::set($key);

        try {
            return $callback();
        } finally {
            self::set($previous);
        }
    }
}
