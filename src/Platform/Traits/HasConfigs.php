<?php

namespace Innertia\Platform\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Innertia\Platform\Builders\ConfigBuilder;
use Innertia\Platform\Models\Config;

/**
 * Trait base polimórfico de configuraciones.
 *
 * Incluir en cualquier modelo que necesite configs:
 *   use HasConfigs;
 *
 * Acceso genérico por type:
 *   $user->configsOfType('preference')          → ConfigBuilder
 *   $user->configValue('preference', 'lang')    → 'es'
 *   $user->setConfigValue('preference', 'lang', 'en', 'public')
 */
trait HasConfigs
{
    public function configs(): MorphMany
    {
        return $this->morphMany(Config::class, 'owner');
    }

    public function configsOfType(string $type): ConfigBuilder
    {
        return new ConfigBuilder($this, $type);
    }

    public function configValue(string $type, string $key, mixed $default = null): mixed
    {
        return $this->configsOfType($type)->get($key, $default);
    }

    public function setConfigValue(
        string $type,
        string $key,
        mixed $value,
        string $privacy = 'private',
        string $cast = 'string',
    ): Config {
        return $this->configsOfType($type)->set($key, $value, $privacy, $cast);
    }
}
