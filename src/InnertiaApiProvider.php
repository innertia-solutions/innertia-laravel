<?php

namespace Innertia;

use Innertia\Api\Middleware\VerifyApiKey;

/**
 * Service provider for API product mode.
 *
 * Use for internal API products (no users, no tenants) that authenticate
 * via organization API keys. Examples: Cognitia, email engines, billing services.
 *
 * Register in bootstrap/providers.php:
 *
 *   return [
 *       Innertia\InnertiaApiProvider::class,
 *       App\AppProvider::class,
 *   ];
 */
class InnertiaApiProvider extends InnertiaServiceProvider
{
    protected function isSaas(): bool { return false; }
    protected function isApi(): bool  { return true; }

    public function boot(): void
    {
        parent::boot();

        $this->app['router']->aliasMiddleware('verify.api.key', VerifyApiKey::class);
    }
}
