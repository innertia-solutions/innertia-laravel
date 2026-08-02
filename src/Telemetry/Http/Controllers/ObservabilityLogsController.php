<?php

namespace Innertia\Telemetry\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Facades\DataTable;
use Innertia\Telemetry\Models\TelemetryEvent;

class ObservabilityLogsController extends Controller
{
    public function index(Request $request)
    {
        $channel = $request->query('channel', 'operational');

        return DataTable::create('observability-logs')
            ->columns(['id', 'channel', 'occurred_at', 'payload', 'context'])
            ->prepareQuery(fn ($query) => $query
                ->where('type', 'log')
                ->where('channel', $channel))
            ->render(TelemetryEvent::query(), $request, 'occurred_at', 'desc');
    }
}
