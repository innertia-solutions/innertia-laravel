<?php

namespace Innertia\DataTable\Exporters;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Innertia\DataTable\Contracts\ExporterInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class XlsxExporter implements ExporterInterface
{
    public function export(
        array $data,
        Request $request,
        array $columns,
        array $relationships,
        string $name,
    ): Response {
        $filename = $name . '_' . now()->format('Y-m-d_His') . '.xlsx';
        $tmpPath  = sys_get_temp_dir() . '/' . uniqid('innertia_export_', true) . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($tmpPath);

        // Header row — bold + light grey background
        $headerStyle = (new Style())
            ->setFontBold()
            ->setBackgroundColor('F3F4F6');

        $writer->addRow(Row::fromValues($columns, $headerStyle));

        // Data rows
        foreach ($data as $row) {
            // toSnakeCase() del DataTable puede devolver stdClass — normalizar a array.
            $row = is_object($row) ? (array) $row : $row;
            $values = array_map(fn ($col) => $this->flatten($row[$col] ?? ''), $columns);
            $writer->addRow(Row::fromValues($values));
        }

        $writer->close();

        $content = file_get_contents($tmpPath);
        @unlink($tmpPath);

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function flatten(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) ($value ?? '');
    }
}
