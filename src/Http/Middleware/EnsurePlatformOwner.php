<?php

namespace Innertia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the platform owner (is_platform_admin + platform_role = 'owner') passes; staff → 403.
 * Alias: `platform.owner`. Use it inside the product's authenticated platform group for
 * sensitive sections (mail provider credentials, backups, …).
 */
class EnsurePlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! ($user->is_platform_admin ?? false) || ($user->platform_role ?? null) !== 'owner') {
            return response()->json([
                'message' => 'Solo el owner de la plataforma puede realizar esta acción.',
                'code'    => 'platform_owner_required',
            ], 403);
        }

        return $next($request);
    }
}
