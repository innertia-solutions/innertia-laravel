<?php

namespace Innertia\Billing\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/** Billing reports and account statements as .xlsx (openspout). Money stays as integer pesos. */
final class XlsxExport
{
    /**
     * @param  array<string, string>  $columns  key => header
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function download(string $name, array $columns, array $rows, array $preamble = []): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'billing_') . '.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($preamble as $line) {
            $writer->addRow(Row::fromValues(array_values((array) $line)));
        }
        if ($preamble !== []) {
            $writer->addRow(Row::fromValues([]));
        }
        $writer->addRow(Row::fromValues(array_values($columns), (new Style)->setFontBold()->setBackgroundColor('F3F4F6')));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(function ($key) use ($row) {
                $value = $row[$key] ?? null;

                return is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'Sí' : 'No') : $value);
            }, array_keys($columns))));
        }
        $writer->close();

        $content = (string) file_get_contents($path);
        @unlink($path);
        $file = preg_replace('/[^a-z0-9_-]+/i', '-', $name) . '_' . now()->format('Y-m-d') . '.xlsx';

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$file}\"",
        ]);
    }
}
