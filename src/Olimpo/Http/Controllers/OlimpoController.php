<?php

namespace Innertia\Olimpo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
            'app'       => $appHealth,
            'metrics'   => SystemMetrics::collect(),
            'logs'      => $logs,
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

    public function getTenantBackups(string $id): JsonResponse
    {
        return response()->json($this->handler->getTenantBackups($id));
    }

    public function createBackup(string $id): JsonResponse
    {
        return response()->json($this->handler->createBackup($id), 201);
    }
}
