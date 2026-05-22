<?php

namespace Innertia\Platform\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Innertia\Platform\Models\Config;

/**
 * Builder fluente para consultar y mutar configs de un owner.
 *
 * Uso:
 *   $user->preferences()->onlyPublic()->all()
 *   $user->preferences()->get('appearance', 'light')
 *   $user->preferences()->set('appearance', 'dark')
 *   $tenant->settings()->private()->get('max_seats')
 */
class ConfigBuilder
{
    public function __construct(
        private readonly Model $owner,
        private readonly string $type,
        private bool $onlyPublic = false,
    ) {}

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function onlyPublic(): static
    {
        $this->onlyPublic = true;
        return $this;
    }

    public function private(): static
    {
        $this->onlyPublic = false;
        return $this;
    }

    // ── Lectura ───────────────────────────────────────────────────────────────

    /** Retorna todos los configs como Collection de modelos Config. */
    public function all(): Collection
    {
        return $this->query()->get();
    }

    /** Retorna todos los configs como array key → value deserializado. */
    public function toArray(): array
    {
        return $this->query()->get()
            ->mapWithKeys(fn (Config $c) => [$c->key => $c->getValue()])
            ->all();
    }

    /** Obtiene el valor deserializado de una key. */
    public function get(string $key, mixed $default = null): mixed
    {
        $record = $this->query()->where('key', $key)->first();
        return $record ? $record->getValue() : $default;
    }

    public function has(string $key): bool
    {
        return $this->query()->where('key', $key)->exists();
    }

    // ── Escritura ─────────────────────────────────────────────────────────────

    /**
     * Crea o actualiza un config.
     *
     * @param  string  $privacy  'public' | 'private'
     * @param  string  $cast     'string' | 'boolean' | 'integer' | 'json' | 'encrypted'
     */
    public function set(
        string $key,
        mixed $value,
        string $privacy = 'private',
        string $cast = 'string',
    ): Config {
        $encoded = Config::encodeValue($value, $cast);

        /** @var Config $config */
        $config = $this->baseQuery()->updateOrCreate(
            [
                'owner_type' => get_class($this->owner),
                'owner_id'   => $this->owner->getKey(),
                'type'       => $this->type,
                'key'        => $key,
            ],
            ['value' => $encoded, 'cast' => $cast, 'privacy' => $privacy],
        );

        return $config;
    }

    public function delete(string $key): bool
    {
        return (bool) $this->baseQuery()->where('key', $key)->delete();
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    private function baseQuery(): Builder
    {
        return Config::query()
            ->where('owner_type', get_class($this->owner))
            ->where('owner_id', $this->owner->getKey())
            ->where('type', $this->type);
    }

    private function query(): Builder
    {
        $q = $this->baseQuery();

        if ($this->onlyPublic) {
            $q->where('privacy', 'public');
        }

        return $q;
    }
}
