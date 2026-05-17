<?php

namespace Innertia\Saas\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant from the request subdomain.
 *
 * Given host: acme.api.tuproducto.com
 * → extracts "acme" → activates tenant with key "acme"
 *
 * Config:
 *   innertia.saas.central_domains  — domains treated as "no tenant" (localhost, etc.)
 *   innertia.saas.api_domain       — optional: only resolve when host ends with this domain
 *                                    e.g. 'api.tuproducto.com' → only acme.api.tuproducto.com
 *
 * Usage:
 *   Route::middleware(['tenant.subdomain', 'apikey'])->group(...)
 *   Route::middleware(['tenant.subdomain', 'auth:api'])->group(...)  // also works for JWT routes
 */
class ResolveTenantFromSubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host           = $request->getHost(); // acme.api.tuproducto.com
        $centralDomains = config('innertia.saas.central_domains', ['localhost', '127.0.0.1']);
        $apiDomain      = config('innertia.saas.api_domain');   // e.g. "api.tuproducto.com"

        // Skip resolution on central/landlord domains
        if (in_array($host, $centralDomains, true)) {
            return $next($request);
        }

        $subdomain = $this->extractSubdomain($host, $apiDomain);

        if (! $subdomain) {
            return $next($request);
        }

        $tenantModel = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $tenant      = $tenantModel::where('key', $subdomain)->first();

        if (! $tenant) {
            return response()->json([
                'message' => "Tenant '{$subdomain}' not found.",
                'error'   => 'tenant_not_found',
            ], 404);
        }

        Innertia::activate($tenant->key);

        return $next($request);
    }

    private function extractSubdomain(string $host, ?string $baseDomain): ?string
    {
        // If a specific API domain is configured, only resolve on that domain
        if ($baseDomain) {
            $baseDomain = ltrim($baseDomain, '.');

            if (! str_ends_with($host, '.' . $baseDomain) && $host !== $baseDomain) {
                return null;
            }

            // acme.api.tuproducto.com → remove ".api.tuproducto.com" → "acme"
            $prefix = substr($host, 0, strlen($host) - strlen('.' . $baseDomain));

            // Must be a single-level subdomain (no dots)
            return str_contains($prefix, '.') ? null : $prefix;
        }

        // Generic: first segment of host
        $parts = explode('.', $host);

        // Need at least 3 parts: subdomain.domain.tld
        if (count($parts) < 3) {
            return null;
        }

        return $parts[0];
    }
}
