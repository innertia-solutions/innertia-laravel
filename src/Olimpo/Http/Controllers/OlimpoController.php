<?php

namespace Innertia\Olimpo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Exports\TenantExport;
use Innertia\Models\TenantExportRecord;
use Innertia\Olimpo\Contracts\OlimpoHandler;
use Innertia\Olimpo\Logging\OlimpoLogHandler;
use Innertia\Olimpo\Metrics\SystemMetrics;

class OlimpoController extends Controller
{
    public function __construct(private OlimpoHandler $handler) {}

    public function health(Request $request): JsonResponse
    {
        $flush = $request->boolean('flush_logs', true);

        $appHealth = $this->handler->health();
        $logs      = $flush ? OlimpoLogHandler::flush() : OlimpoLogHandler::peek();

        return response()->json([
            'status'    => 'ok',
            'timestamp' => now()->toISOString(),

            // Product identity — Olimpo uses this to match the backend
            // to its registered product + environment.
            'product' => [
                'name'        => config('app.name'),
                'mode'        => config('innertia.mode', 'app'), // 'app' | 'saas'
                'environment' => app()->environment(),            // 'local' | 'staging' | 'production'
                'version'     => config('app.version', null),
                'url'         => config('app.url'),
                'debug'       => config('app.debug', false),
                'laravel'     => app()->version(),
                'php'         => PHP_VERSION,
            ],

            'app'     => $appHealth,
            'metrics' => SystemMetrics::collect(),
            'logs'    => $logs,
        ]);
    }

    public function createTenant(Request $request): JsonResponse
    {
        return response()->json($this->handler->createTenant($request->all()), 201);
    }

    public function getTenant(string $id): JsonResponse
    {
        return response()->json($this->handler->getTenant($id));
    }

    public function deleteTenant(string $id): JsonResponse
    {
        return response()->json($this->handler->deleteTenant($id));
    }

    public function suspendTenant(string $id): JsonResponse
    {
        return response()->json($this->handler->suspendTenant($id));
    }

    public function reactivateTenant(string $id): JsonResponse
    {
        return response()->json($this->handler->reactivateTenant($id));
    }

    public function updateTrial(string $id, Request $request): JsonResponse
    {
        return response()->json($this->handler->updateTrial($id, $request->all()));
    }

    public function flushCache(string $id): JsonResponse
    {
        return response()->json($this->handler->flushCache($id));
    }

    public function getTenantUsers(string $id): JsonResponse
    {
        return response()->json($this->handler->getTenantUsers($id));
    }

    public function impersonate(string $id, string $userId): JsonResponse
    {
        return response()->json($this->handler->impersonate($id, $userId));
    }

    /**
     * GET /olimpo/tenants/{id}/backups
     *
     * Lists all TenantExportRecords for a specific tenant.
     * Falls back to OlimpoHandler::getTenantBackups() if no records found
     * (backwards compatibility with apps that implement their own backup logic).
     */
    public function getTenantBackups(string $id): JsonResponse
    {
        $records = TenantExportRecord::where('tenant_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TenantExportRecord $r) => [
                'id'           => $r->id,
                'status'       => $r->status,
                'size_mb'      => $r->sizeMb(),
                'checksum'     => $r->checksum,
                'error'        => $r->error,
                'completed_at' => $r->completed_at?->toISOString(),
                'created_at'   => $r->created_at->toISOString(),
            ]);

        if ($records->isNotEmpty()) {
            return response()->json($records);
        }

        // Fallback to app-level handler
        return response()->json($this->handler->getTenantBackups($id));
    }

    /**
     * POST /olimpo/tenants/{id}/backups
     *
     * Triggers a tenant export for the given tenant ID.
     * The export class must be registered in config('innertia.exports.handler').
     * Falls back to OlimpoHandler::createBackup() if not configured.
     */
    public function createBackup(string $id): JsonResponse
    {
        $exportClass = config('innertia.exports.handler');

        if ($exportClass && is_subclass_of($exportClass, TenantExport::class)) {
            // Resolve the tenant model
            $tenantModel = config('innertia.saas.tenant_model', \Innertia\Models\Tenant::class);
            $tenant      = $tenantModel::findOrFail($id);

            $record = (new $exportClass)->queue($tenant);

            return response()->json([
                'id'         => $record->id,
                'status'     => $record->status,
                'tenant_id'  => $id,
                'created_at' => $record->created_at->toISOString(),
            ], 201);
        }

        // Fallback to app-level handler
        return response()->json($this->handler->createBackup($id), 201);
    }
}
