<?php

namespace Innertia\DataTable\Exporters;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Innertia\DataTable\Contracts\ExporterInterface;

class JsonExporter implements ExporterInterface
{
    public function export(
        array $data,
        Request $request,
        array $columns,
        array $relationships,
        string $name,
    ): Response {
        $filename = $name . '_' . now()->format('Y-m-d_His') . '.json';

        // Filter each row to only the requested columns
        $filtered = array_map(
            fn ($row) => array_intersect_key($row, array_flip($columns)),
            $data
        );

        return response(
            json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            200,
            [
                'Content-Type'        => 'application/json; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }
}
