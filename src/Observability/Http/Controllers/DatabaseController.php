<?php

namespace Innertia\Observability\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\Observability\Database\DatabaseConnections;
use Innertia\Observability\Database\DatabaseIndexes;
use Innertia\Observability\Database\DatabaseOverview;
use Innertia\Observability\Database\DatabaseQueries;
use Innertia\Observability\Database\DatabaseTables;
use Innertia\Telemetry\Health\SystemHealth;

class DatabaseController extends Controller
{
    private function topLimit(): int
    {
        return (int) config('innertia.observability.top_limit', 20);
    }

    public function overview(): JsonResponse
    {
        $health = (new SystemHealth())->collect();
        return response()->json((new DatabaseOverview())->collect() + [
            'disk' => $health['disk'] ?? null,
        ]);
    }

    public function connections(): JsonResponse
    {
        return response()->json((new DatabaseConnections())->collect());
    }

    public function queries(): JsonResponse
    {
        return response()->json((new DatabaseQueries())->collect($this->topLimit()));
    }

    public function indexes(): JsonResponse
    {
        return response()->json((new DatabaseIndexes())->collect($this->topLimit()));
    }

    public function tables(): JsonResponse
    {
        return response()->json((new DatabaseTables())->collect($this->topLimit()));
    }
}
