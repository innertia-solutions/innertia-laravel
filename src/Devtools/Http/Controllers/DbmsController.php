<?php

namespace Innertia\Devtools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Innertia\Devtools\Dbms\RowBrowser;
use Innertia\Devtools\Dbms\RowEditor;
use Innertia\Devtools\Dbms\TableInspector;

class DbmsController extends Controller
{
    public function tables(): JsonResponse
    {
        return response()->json(TableInspector::tables());
    }

    public function rows(Request $request, string $table): JsonResponse
    {
        $data = $request->validate([
            'page'               => 'nullable|integer|min:1',
            'per_page'           => 'nullable|integer|min:1|max:500',
            'sort_by'            => 'nullable|string',
            'sort_dir'           => 'nullable|in:asc,desc',
            'filters'            => 'nullable|array',
            'filters.*.column'   => 'required|string',
            'filters.*.operator' => 'nullable|in:=,!=,<,>,<=,>=,like,not like',
            'filters.*.value'    => 'required',
        ]);

        return response()->json(
            RowBrowser::browse(
                table:   $table,
                page:    $data['page']     ?? 1,
                perPage: $data['per_page'] ?? 50,
                filters: $data['filters']  ?? [],
                sortBy:  $data['sort_by']  ?? 'id',
                sortDir: $data['sort_dir'] ?? 'asc',
            )
        );
    }

    public function updateRow(Request $request, string $table, string $id): JsonResponse
    {
        $data = $request->validate([
            'column' => 'required|string',
            'value'  => 'nullable',
        ]);

        $updated = RowEditor::update($table, $id, $data['column'], $data['value']);

        return response()->json(['updated' => $updated]);
    }

    public function query(Request $request): JsonResponse
    {
        $data = $request->validate(['sql' => 'required|string']);

        if (! preg_match('/^\s*select\s/i', $data['sql'])) {
            return response()->json(['message' => 'Only SELECT queries are allowed.'], 422);
        }

        $results = DB::select($data['sql']);

        return response()->json(['data' => $results, 'count' => count($results)]);
    }
}
