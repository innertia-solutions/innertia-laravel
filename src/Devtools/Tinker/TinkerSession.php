<?php

namespace Innertia\Devtools\Tinker;

use Illuminate\Support\Str;

class TinkerSession
{
    private function __construct(private readonly string $id) {}

    public static function create(): self
    {
        $session = new self(Str::uuid()->toString());
        $session->save([]);

        return $session;
    }

    public static function find(string $id): ?self
    {
        if (self::store()->has(self::cacheKey($id))) {
            return new self($id);
        }

        return null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function channel(): string
    {
        return "private-innertia.tinker.{$this->id}";
    }

    public function variables(): array
    {
        return self::store()->get(self::cacheKey($this->id), []);
    }

    public function save(array $variables): void
    {
        $ttl = config('innertia.devtools.tinker.session_ttl', 1800);
        self::store()->put(self::cacheKey($this->id), $variables, $ttl);
    }

    public function destroy(): void
    {
        self::store()->forget(self::cacheKey($this->id));
    }

    /**
     * Uses the explicitly configured cache store — never the app default,
     * which could be 'octane' (in-memory, worker-local) and break sessions
     * when requests land on different workers.
     */
    private static function store(): \Illuminate\Contracts\Cache\Repository
    {
        return cache()->store(
            config('innertia.devtools.tinker.cache_store', 'redis')
        );
    }

    private static function cacheKey(string $id): string
    {
        return "devtools_tinker_{$id}";
    }
}
