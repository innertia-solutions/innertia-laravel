<?php

namespace Innertia\Telemetry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Innertia\Facades\DataTable;
use Innertia\Telemetry\Models\TelemetryCase;

class ObservabilityErrorsController extends Controller
{
    public function index(Request $request)
    {
        return DataTable::create('observability-errors')
            ->columns([
                'id', 'title', 'exception_class', 'status',
                'times_seen', 'first_seen', 'last_seen', 'last_trace_id',
            ])
            ->render(TelemetryCase::query(), $request, 'last_seen', 'desc');
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
