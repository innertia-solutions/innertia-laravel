<?php

namespace Innertia\DataTable\Exporters;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Innertia\DataTable\Contracts\ExporterInterface;

class CsvExporter implements ExporterInterface
{
    public function export(
        array $data,
        Request $request,
        array $columns,
        array $relationships,
        string $name,
    ): Response {
        $filename = $name . '_' . now()->format('Y-m-d_His') . '.csv';

        $csv = $this->build($data, $columns);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function build(array $data, array $columns): string
    {
        $buffer = fopen('php://temp', 'r+');

        // BOM for Excel UTF-8 compatibility
        fputs($buffer, "\xEF\xBB\xBF");

        // Header row
        fputcsv($buffer, $columns);

        // Data rows
        foreach ($data as $row) {
            $values = array_map(fn ($col) => $this->flatten($row[$col] ?? ''), $columns);
            fputcsv($buffer, $values);
        }

        rewind($buffer);
        $content = stream_get_contents($buffer);
        fclose($buffer);

        return $content;
    }

    private function flatten(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) ($value ?? '');
    }
}
