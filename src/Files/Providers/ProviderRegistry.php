<?php

namespace Innertia\Files\Providers;

class ProviderRegistry
{
    public function for(string $provider): FileProvider
    {
        $cfg = config("innertia.files.providers.{$provider}");

        if (! $cfg || ! ($cfg['enabled'] ?? false)) {
            throw new \RuntimeException("Provider '{$provider}' no está habilitado.");
        }

        return app($cfg['driver']);
    }
}
