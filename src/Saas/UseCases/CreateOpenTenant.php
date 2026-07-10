<?php

namespace Innertia\Saas\UseCases;

use Illuminate\Support\Str;
use Innertia\Auth\Models\UserContext;
use Innertia\Platform\Contracts\UseCase;

/**
 * Alta self-serve de tenant (gym) en modo open.
 *
 * Crea el tenant reutilizando CreateTenant y deja al usuario que lo crea
 * como admin del backoffice mediante un user_context.
 *
 * OJO: user_contexts.tenant_id guarda el id NUMÉRICO del tenant (como string),
 * NO el key. Tenant::getKey() devuelve ese id numérico.
 */
class CreateOpenTenant extends UseCase
{
    public function __construct(
        public readonly string $name,
        public readonly string $ownerUserId,
        public readonly string $status = 'active',
    ) {}

    public function execute(): array
    {
        $key = $this->uniqueKey($this->name);
        $tenant = (new CreateTenant(key: $key, name: $this->name, status: $this->status))->execute();

        UserContext::create([
            'user_id'   => $this->ownerUserId,
            'context'   => 'backoffice',
            'tenant_id' => (string) $tenant->getKey(), // id NUMÉRICO, así se almacena
        ]);

        return ['tenant' => $tenant];
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name) ?: 'gym';
        $model = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $key = $base;
        $i = 1;
        while ($model::where('key', $key)->exists()) {
            $key = $base.'-'.(++$i);
        }

        return $key;
    }
}
