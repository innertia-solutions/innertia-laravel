<?php

namespace Innertia\Olimpo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Saas\Exports\TenantExport;
use Innertia\Saas\UseCases\EnableTenantDemo;
use Innertia\Saas\UseCases\DisableTenantDemo;
use Innertia\Platform\Models\Process;
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
        $processes = Process::where('type', 'export')
            ->whereJsonContains('metadata->tenant_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Process $p) => [
                'id'           => $p->id,
                'status'       => $p->status,
                'category'     => $p->category,
                'size_mb'      => $p->metadata['size_mb'] ?? null,
                'checksum'     => $p->metadata['checksum'] ?? null,
                'rows_exported'=> $p->metadata['rows_exported'] ?? null,
                'file_id'      => $p->metadata['file_id'] ?? null,
                'error'        => $p->metadata['error'] ?? null,
                'completed_at' => $p->completed_at?->toISOString(),
                'created_at'   => $p->created_at->toISOString(),
            ]);

        if ($processes->isNotEmpty()) {
            return response()->json($processes);
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
            $tenantModel = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
            $tenant      = $tenantModel::findOrFail($id);

            /** @var Process $process */
            $process = (new $exportClass)->queue($tenant);

            return response()->json([
                'id'         => $process->id,
                'status'     => $process->status,
                'type'       => $process->type,
                'tenant_id'  => $id,
                'created_at' => $process->created_at->toISOString(),
            ], 201);
        }

        // Fallback to app-level handler
        return response()->json($this->handler->createBackup($id), 201);
    }

    /**
     * PUT /olimpo/tenants/{id}/demo
     *
     * Enables demo mode for the tenant, storing plain-text credentials
     * that the public /ping endpoint will expose to the login page.
     *
     * Body: { "email": "demo@acme.com", "password": "demo1234" }
     */
    public function enableDemo(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $tenant = (new EnableTenantDemo(
            tenantKey: $id,
            email:     $data['email'],
            password:  $data['password'],
        ))->execute();

        return response()->json([
            'ok'    => true,
            'demo'  => $tenant->configs['demo'],
        ]);
    }

    /**
     * DELETE /olimpo/tenants/{id}/demo
     *
     * Disables demo mode for the tenant and removes the stored credentials.
     */
    public function disableDemo(string $id): JsonResponse
    {
        (new DisableTenantDemo(tenantKey: $id))->execute();

        return response()->json(['ok' => true]);
    }
}
