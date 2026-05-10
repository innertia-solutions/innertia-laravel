<?php

namespace Innertia\Olimpo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OlimpoAuth
{
    public function handle(Request $request, Closure $next): mixed
    {
        $expected = config('olimpo.key');

        if (empty($expected)) {
            return response()->json(['message' => 'OLIMPO_KEY not configured'], 500);
        }

        $provided = $request->header('X-Olimpo-Key');

        if (!$provided || !hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
