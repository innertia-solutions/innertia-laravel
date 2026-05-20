<?php

namespace Innertia\Devtools\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DevtoolsGuard
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('innertia.devtools.enabled', false)) {
            return response()->json(['message' => 'Devtools not enabled. Set DEVTOOLS_ENABLED=true.'], 403);
        }

        return $next($request);
    }
}
