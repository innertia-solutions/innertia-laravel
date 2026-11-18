<?php

namespace Innertia\Saas\Clients;

/** Clients → spaces: every tenant belongs to a client. Opt-in, only in saas/open modes. */
final class ClientsFeature
{
    public static function isActive(): bool
    {
        return (bool) config('innertia.saas.clients.enabled', false)
            && in_array(config('innertia.mode'), ['saas', 'open'], true);
    }
}
