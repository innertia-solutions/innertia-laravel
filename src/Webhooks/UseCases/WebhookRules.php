<?php

namespace Innertia\Webhooks\UseCases;

use Illuminate\Validation\ValidationException;
use Innertia\ApiKeys\ApiScopes;
use Innertia\Webhooks\Security\UrlGuard;
use Innertia\Webhooks\WebhookEvents;

/** Domain checks shared by CreateWebhook / UpdateWebhook (URL safety, event patterns, scopes). */
final class WebhookRules
{
    public static function assertUrl(string $url): void
    {
        if ($problem = app(UrlGuard::class)->problem($url)) {
            throw ValidationException::withMessages(['url' => $problem]);
        }
    }

    public static function assertEvents(array $events): void
    {
        if ($events === []) {
            throw ValidationException::withMessages(['events' => 'Selecciona al menos un evento.']);
        }

        $invalid = array_values(array_filter($events, fn ($e) => ! is_string($e) || ! WebhookEvents::isValidPattern($e)));

        if ($invalid !== []) {
            throw ValidationException::withMessages(['events' => 'Eventos desconocidos: ' . implode(', ', $invalid) . '.']);
        }
    }

    public static function assertScopes(array $scopes): void
    {
        if ($unknown = ApiScopes::unknown($scopes)) {
            throw ValidationException::withMessages(['scopes' => 'Scopes desconocidos: ' . implode(', ', $unknown) . '.']);
        }
    }
}
