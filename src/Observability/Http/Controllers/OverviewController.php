<?php

namespace Innertia\Observability\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\Observability\Database\DatabaseConnections;
use Innertia\Observability\Database\DatabaseOverview;
use Innertia\Observability\Database\DatabaseQueries;
use Innertia\Observability\Support\Criticality;
use Innertia\Telemetry\Health\SystemHealth;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;

class OverviewController extends Controller
{
    public function index(): JsonResponse
    {
        $threshold = Criticality::threshold();
        $byStatus = TelemetryCase::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $overview    = (new DatabaseOverview())->collect();
        $connections = (new DatabaseConnections())->collect();
        $queue       = (new SystemHealth())->collect()['queue'] ?? ['pending' => null, 'failed' => null];

        return response()->json([
            'errors' => [
                'open'     => (int) ($byStatus['open'] ?? 0),
                'resolved' => (int) ($byStatus['resolved'] ?? 0),
                'ignored'  => (int) ($byStatus['ignored'] ?? 0),
                'critical' => (int) TelemetryCase::where('status', 'open')->where('times_seen', '>=', $threshold)->count(),
            ],
            'logs' => [
                'errors_24h' => (int) TelemetryEvent::where('type', 'exception')
                    ->where('occurred_at', '>=', now()->subDay())->count(),
            ],
            'database' => [
                'database_bytes'  => $overview['database_bytes'] ?? null,
                'cache_hit_ratio' => $overview['cache_hit_ratio'] ?? null,
                'connections'     => ['total' => $connections['total'] ?? null, 'max' => $connections['max'] ?? null],
            ],
            'slow_queries' => array_slice((new DatabaseQueries())->collect(3)['top'] ?? [], 0, 3),
            'queue' => $queue,
        ]);
    }
}
