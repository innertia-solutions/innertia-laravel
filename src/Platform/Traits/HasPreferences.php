<?php

namespace Innertia\Platform\Traits;

use Innertia\Platform\Builders\ConfigBuilder;
use Innertia\Platform\Models\Config;

/**
 * Preferencias de usuario — type='preference', privacy='public' por defecto.
 *
 * Uso:
 *   $user->preferences()->get('appearance', 'light')
 *   $user->preferences()->onlyPublic()->toArray()
 *   $user->preferences()->set('appearance', 'dark')
 *   $user->preference('appearance', 'light')
 *   $user->setPreference('appearance', 'dark')
 */
trait HasPreferences
{
    use HasConfigs;

    public function preferences(): ConfigBuilder
    {
        return $this->configsOfType('preference');
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return $this->preferences()->get($key, $default);
    }

    public function setPreference(string $key, mixed $value, string $cast = 'string'): Config
    {
        return $this->preferences()->set($key, $value, privacy: 'public', cast: $cast);
    }
}
