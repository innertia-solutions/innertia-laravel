<?php

namespace Innertia\PublicApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Innertia\ApiKeys\IntegrationKeys;
use Innertia\ApiKeys\Models\ApiKey;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationsFeature;
use Innertia\PublicApi\ActsAsIntegration;
use Innertia\PublicApi\Docs\RouteDescriptor;
use Innertia\PublicApi\Http\ApiError;
use Innertia\PublicApi\Models\ApiRequestLog;
use Innertia\PublicApi\Support\IdempotencyGuard;
use Innertia\PublicApi\Support\IntegrationsSchema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `api.key` — public API authentication with integration keys (pk_live_…).
 *
 *   Route::prefix('v1')->middleware('api.key')->group(function () {
 *       Route::get('calls', [CallsController::class, 'index'])->apiDoc(['summary' => 'Lista llamadas', 'scope' => 'calls:read']);
 *       Route::post('calls', [...])->middleware('api.key:calls:write');   // scope as parameter also works
 *   });
 *
 * - Key from `Authorization: Bearer` or `X-Api-Key`; invalid/revoked/expired → 401 invalid_api_key.
 * - Activates the key's tenant (and sede when Organizations is on) for the request; restores afterwards.
 * - Requires the route scope (middleware parameters ∪ descriptor `scope`) → 403 insufficient_scope.
 * - Rate limit per key (default 120/min) and per space (default 600/min); X-RateLimit-* headers; 429 + Retry-After.
 * - Idempotency-Key on writes (24 h replay; other body → 409 idempotency_conflict).
 * - Error responses normalized to {error: {code, message, details}}.
 * - Request log (method, path, status, ms, key prefix; 30 days). The key itself is never logged.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        // Nested use (group `api.key` + route `api.key:calls:write`): the outer pass already authenticated,
        // limited, logged and handles idempotency; the inner one only enforces its extra scopes.
        if (($current = $request->attributes->get('api_key')) instanceof ApiKey && $request->attributes->get('api_key_outer')) {
            $missing = array_values(array_filter($scopes, fn ($s) => ! $current->hasScope($s)));

            return $missing === []
                ? $next($request)
                : ApiError::response('insufficient_scope', 'La clave no tiene el scope requerido para esta operación.', 403, ['required' => $missing]);
        }

        $start = hrtime(true);
        $raw = $request->bearerToken() ?: (string) $request->header('X-Api-Key', '');
        $key = $raw !== '' ? IntegrationKeys::find($raw) : null;

        if ($key === null) {
            return ApiError::forStatus(401);
        }

        $previousTenant = Innertia::tenancyEnabled() ? Innertia::tenant() : null;
        $orgContext = OrganizationsFeature::isActive() ? Innertia::organization() : null;
        $previousOrg = $orgContext ? [$orgContext->current(), $orgContext->scope()] : null;
        $previousActor = ActsAsIntegration::current();
        $response = null;

        try {
            if (! $this->activateTenant($key)) {
                return ApiError::forStatus(401);
            }

            if ($orgContext && filled($key->organization_id) && is_numeric($key->organization_id)) {
                $orgContext->set((int) $key->organization_id);
            }

            $required = array_values(array_unique(array_filter([...$scopes, RouteDescriptor::scopeFor($request->route())])));
            $missing = array_values(array_filter($required, fn ($s) => ! $key->hasScope($s)));

            if ($missing !== []) {
                return $response = ApiError::response('insufficient_scope', 'La clave no tiene el scope requerido para esta operación.', 403, ['required' => $missing]);
            }

            if ($limited = $this->rateLimit($key)) {
                return $response = $limited;
            }

            ActsAsIntegration::set($key);
            $request->attributes->set('api_key', $key);
            $request->attributes->set('api_key_outer', true);
            $this->touch($key, $request);

            $response = app(IdempotencyGuard::class)->handle($request, $key, fn () => $next($request));
            $response = ApiError::normalize($response);
            $this->rateHeaders($response, $key);

            return $response;
        } finally {
            if ($response !== null) {
                $this->logRequest($request, $key, $response->getStatusCode(), $start);
            }

            ActsAsIntegration::set($previousActor);
            $request->attributes->remove('api_key_outer');

            if ($orgContext && $previousOrg !== null) {
                [$current, $scope] = $previousOrg;
                $orgContext->clear();
                if ($current !== null) {
                    $orgContext->set($current);
                }
                $orgContext->setScope($scope);
            }

            if (Innertia::tenancyEnabled()) {
                $previousTenant ? app(\Innertia\Saas\TenantContext::class)->set($previousTenant) : Innertia::deactivate();
            }
        }
    }

    private function activateTenant(ApiKey $key): bool
    {
        if (! Innertia::tenancyEnabled()) {
            return true;
        }

        $model = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $tenant = $model::query()->whereKey($key->tenant_id)->first();

        if (! $tenant || (isset($tenant->status) && ! in_array($tenant->status, ['active', 'trial', null], true))) {
            return false;
        }

        app(\Innertia\Saas\TenantContext::class)->set($tenant);

        return true;
    }

    private function rateLimit(ApiKey $key): ?Response
    {
        foreach ($this->limits($key) as [$bucket, $max]) {
            if (RateLimiter::tooManyAttempts($bucket, $max)) {
                $retry = RateLimiter::availableIn($bucket);

                return ApiError::forStatus(429, null, [
                    'Retry-After'           => (string) $retry,
                    'X-RateLimit-Limit'     => (string) $max,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset'     => (string) (time() + $retry),
                ]);
            }
        }

        foreach ($this->limits($key) as [$bucket]) {
            RateLimiter::hit($bucket, 60);
        }

        return null;
    }

    /** @return array<int, array{0: string, 1: int}> [bucket, max per minute] */
    private function limits(ApiKey $key): array
    {
        $limits = [['public-api:key:' . $key->id, (int) config('innertia.public_api.rate_limit.per_key', 120)]];
        $space = $key->tenant_id ?? 'app';
        $limits[] = ['public-api:space:' . $space, (int) config('innertia.public_api.rate_limit.per_space', 600)];

        return $limits;
    }

    private function rateHeaders(Response $response, ApiKey $key): void
    {
        [$bucket, $max] = $this->limits($key)[0];
        $response->headers->set('X-RateLimit-Limit', (string) $max);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($bucket, $max)));
        $response->headers->set('X-RateLimit-Reset', (string) (time() + RateLimiter::availableIn($bucket)));
    }

    private function touch(ApiKey $key, Request $request): void
    {
        try {
            $attributes = ['last_used_at' => now()];
            if (IntegrationsSchema::apiKeysV2()) {
                $attributes['last_used_ip'] = $request->ip();
            }
            $key->forceFill($attributes)->saveQuietly();
        } catch (Throwable) {
            // Best effort.
        }
    }

    private function logRequest(Request $request, ApiKey $key, int $status, int $start): void
    {
        if (! IntegrationsSchema::requestLogs()) {
            return;
        }

        try {
            ApiRequestLog::create([
                'tenant_id'   => $key->tenant_id ?? null,
                'api_key_id'  => $key->id,
                'key_prefix'  => $key->prefix,
                'method'      => $request->method(),
                'path'        => mb_substr('/' . ltrim($request->path(), '/'), 0, 255),
                'route'       => $request->route()?->uri(),
                'status'      => $status,
                'duration_ms' => (int) round((hrtime(true) - $start) / 1e6),
                'ip'          => $request->ip(),
                'trace_id'    => traceId() !== '' ? traceId() : null,
                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
