<?php

namespace Innertia\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Api\Models\ClientApiKey;
use Symfony\Component\HttpFoundation\Response;

class VerifyClientApiKey
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $header = config('innertia.api.key_header', 'X-Api-Key');
        $raw    = $request->header($header);

        if (! $raw) {
            return response()->json(['message' => 'API key required.'], 401);
        }

        $apiKey = ClientApiKey::findByRawKey($raw);

        if (! $apiKey) {
            return response()->json(['message' => 'Invalid or expired API key.'], 401);
        }

        if ($apiKey->client->isSuspended()) {
            return response()->json(['message' => 'Client suspended.'], 403);
        }

        foreach ($permissions as $permission) {
            if (! $apiKey->hasPermission($permission)) {
                return response()->json(['message' => "Permission denied: {$permission}"], 403);
            }
        }

        $apiKey->touchLastUsed();

        $request->attributes->set('client', $apiKey->client);
        $request->attributes->set('client_api_key', $apiKey);

        return $next($request);
    }
}
