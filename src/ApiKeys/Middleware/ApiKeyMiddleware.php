<?php

namespace Innertia\ApiKeys\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\ApiKeys\Models\ApiKey;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests using X-Api-Key header.
 *
 * - Reads the raw key from the configured header (default: X-Api-Key)
 * - Resolves ApiKey model (checks hash, active status)
 * - Activates tenant via Innertia::activate()
 * - If user key: authenticates the user via Auth::onceUsingId()
 * - Injects resolved ApiKey into request as 'api_key'
 *
 * Usage in routes:
 *   Route::middleware('apikey')->group(fn () => require __DIR__ . '/api.clients.php');
 *   Route::middleware('apikey:invoices.read')->get(...)   // with permission check
 */
class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $header = config('innertia.api_keys.header', 'X-Api-Key');
        $raw    = $request->header($header);

        if (! $raw) {
            return $this->unauthorized('API key required.');
        }

        $apiKey = ApiKey::findByRawKey($raw);

        if (! $apiKey) {
            return $this->unauthorized('Invalid or expired API key.');
        }

        // Check requested permissions
        foreach ($permissions as $permission) {
            if (! $apiKey->hasPermission($permission)) {
                return response()->json([
                    'message' => "API key does not have permission: {$permission}.",
                    'error'   => 'forbidden',
                ], 403);
            }
        }

        // Tenant resolution: if already active (e.g. resolved by tenant.subdomain middleware),
        // cross-validate that the key belongs to the same tenant.
        // Otherwise, activate tenant from the key itself.
        $activeTenant = Innertia::tenant();

        if ($activeTenant) {
            // Cross-validate: key must belong to the already-resolved tenant
            if ((string) $activeTenant->getKey() !== (string) $apiKey->tenant_id) {
                return $this->unauthorized('API key does not belong to this tenant.');
            }
        } else {
            $tenantModel = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
            $tenant      = $tenantModel::find($apiKey->tenant_id);

            if (! $tenant) {
                return $this->unauthorized('Tenant not found.');
            }

            Innertia::activate($tenant->key);
        }

        // Authenticate user if it's a user key
        if ($apiKey->type === 'user' && $apiKey->user_id) {
            $userModel = config('auth.providers.users.model');
            $user = $userModel::find($apiKey->user_id);

            if ($user) {
                auth()->setUser($user);
            }
        }

        // Touch last_used_at async (no await — best effort)
        $apiKey->touchLastUsed();

        // Inject into request for controllers
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'message' => $message,
            'error'   => 'unauthorized',
        ], 401);
    }
}
