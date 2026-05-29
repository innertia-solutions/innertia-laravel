<?php

declare(strict_types=1);

namespace Innertia\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Api\Models\ApiKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests using X-Api-Key header (configurable via innertia.api.key_header).
 *
 * On success: injects 'organization' and 'api_key' into request attributes.
 * On failure: 401 Unauthorized or 403 Forbidden (if org suspended).
 *
 * Usage in routes:
 *   Route::middleware('verify.api.key')->group(...)
 */
class VerifyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = config('innertia.api.key_header', 'X-Api-Key');
        $raw    = $request->header($header);

        if (! $raw) {
            return $this->unauthorized('API key required.');
        }

        $apiKey = ApiKey::findByRawKey($raw);

        if (! $apiKey) {
            return $this->unauthorized('Invalid or revoked API key.');
        }

        $organization = $apiKey->organization;

        if (! $organization || $organization->isSuspended()) {
            return response()->json([
                'message' => 'Organization is suspended.',
                'error'   => 'forbidden',
            ], 403);
        }

        $apiKey->touchLastUsed();

        $request->attributes->set('organization', $organization);
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
