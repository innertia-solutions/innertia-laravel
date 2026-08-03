<?php

namespace Innertia\Observability\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Innertia\Facades\DataTable;
use Innertia\Observability\Support\Criticality;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryCaseOccurrence;
use Innertia\Telemetry\Models\TelemetryTrace;

class ErrorsController extends Controller
{
    public function index(Request $request)
    {
        $threshold = Criticality::threshold();

        return DataTable::create('observability-errors')
            ->columns([
                'id', 'title', 'exception_class', 'status',
                'times_seen', 'first_seen', 'last_seen', 'last_trace_id',
                'origin', 'category',
            ])
            ->addCalculatedColumn('is_critical', "(status = 'open' AND times_seen >= {$threshold})")
            // Filtros programáticos (query params planos): la tabla del front pasa el filtro
            // de la columna `status` como `filters[]` estructurado que el motor DataTable aplica
            // por sí solo; estos reads cubren el uso vía la prop `:params` (status/exception_class/
            // rango de fechas) y el atajo `only_critical` para clientes que lo invoquen directo.
            ->prepareQuery(function ($q) use ($request, $threshold) {
                if ($status = $request->query('status')) {
                    $q->where('status', $status);
                }
                if ($class = $request->query('exception_class')) {
                    $q->where('exception_class', 'like', '%' . $class . '%');
                }
                if ($from = $request->query('from')) {
                    $q->where('last_seen', '>=', $from);
                }
                if ($to = $request->query('to')) {
                    $q->where('last_seen', '<=', $to);
                }
                if ($request->query('only_critical')) {
                    $q->where('status', 'open')->where('times_seen', '>=', $threshold);
                }
                if ($origin = $request->query('origin')) {
                    $q->where('origin', $origin);
                }
                if ($category = $request->query('category')) {
                    $q->where('category', $category);
                }

                return $q;
            })
            ->render(TelemetryCase::query(), $request, 'last_seen', 'desc');
    }

    public function show(string $id): JsonResponse
    {
        $case = TelemetryCase::findOrFail($id);

        $trace = TelemetryTrace::where('trace_id', $case->last_trace_id)->first();
        $events = $trace ? $trace->events()->orderBy('occurred_at')->get() : collect();

        $samples = TelemetryTrace::whereIn('trace_id', $case->sample_trace_ids ?? [])
            ->get(['trace_id', 'occurred_at', 'method', 'route', 'url', 'status']);

        $timeline = TelemetryCaseOccurrence::where('case_id', (string) $case->getKey())
            ->where('day', '>=', now()->subDays(30)->toDateString())
            ->orderBy('day')
            ->get(['day', 'count'])
            ->map(fn ($r) => ['day' => (string) $r->day->toDateString(), 'count' => (int) $r->count])
            ->values();

        return response()->json([
            'case'        => $case,
            'is_critical' => Criticality::isCritical($case),
            'trace'       => $trace,
            'events'      => $events,
            'samples'     => $samples,
            'timeline'    => $timeline,
            'snapshot'    => $case->snapshot,
        ]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'resolved', 'ignored'])],
        ]);

        $case = TelemetryCase::findOrFail($id);
        $case->update(['status' => $data['status']]);

        return response()->json($case);
    }
}
