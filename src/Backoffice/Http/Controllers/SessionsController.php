<?php

namespace Innertia\Backoffice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\Auth\Models\Session;
use Innertia\Facades\Innertia;

/**
 * Gestión genérica de sesiones activas (backoffice).
 *
 * Funciona con JWT: cada token emitido se registra como fila en `user_sessions`
 * (token_hash, device_id, ip, browser, expires_at) vía JwtService. Esta vista
 * lista/revoca esas sesiones de forma global para el admin.
 *
 * Rutas (montadas bajo el prefijo backoffice):
 *   GET    sessions          → index
 *   DELETE sessions/{id}     → destroy
 *   DELETE sessions          → destroyAll
 */
class SessionsController extends Controller
{
    /** GET /backoffice/sessions — lista todas las sesiones activas (scoped por tenant en saas). */
    public function index(): JsonResponse
    {
        $sessions = $this->scopedQuery()
            ->where('expires_at', '>', now())
            ->with(['user:id,name,email'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Session $s) => [
                'id'               => $s->id,
                'user'             => $s->user ? [
                    'id'    => $s->user->id,
                    'name'  => $s->user->name,
                    'email' => $s->user->email,
                ] : null,
                'ip'               => $s->ip,
                'device'           => $s->device_id,
                'browser'          => $s->browser,
                'last_activity_at' => optional($s->updated_at)->toIso8601String(),
                'created_at'       => optional($s->created_at)->toIso8601String(),
                'expires_at'       => optional($s->expires_at)->toIso8601String(),
            ]);

        return response()->json($sessions);
    }

    /** DELETE /backoffice/sessions/{id} — revoca una sesión. */
    public function destroy(string $id): JsonResponse
    {
        $this->scopedQuery()->whereKey($id)->delete();

        return response()->json(['ok' => true]);
    }

    /** DELETE /backoffice/sessions — revoca todas las sesiones (del tenant). */
    public function destroyAll(): JsonResponse
    {
        $this->scopedQuery()->delete();

        return response()->json(['ok' => true]);
    }

    /** Query base con scope por tenant cuando el modo es saas. */
    private function scopedQuery()
    {
        $query = Session::query();

        if (config('innertia.mode') === 'saas') {
            $tenantId = Innertia::tenant()?->getKey();
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }
}
