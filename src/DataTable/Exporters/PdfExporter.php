<?php

namespace Innertia\DataTable\Exporters;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Innertia\DataTable\Contracts\ExporterInterface;

class PdfExporter implements ExporterInterface
{
    public function export(
        array $data,
        Request $request,
        array $columns,
        array $relationships,
        string $name,
    ): Response {
        $filename = $name . '_' . now()->format('Y-m-d_His') . '.pdf';

        $html = $this->buildHtml($data, $columns, $name);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', count($columns) > 6 ? 'landscape' : 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildHtml(array $data, array $columns, string $name): string
    {
        $title   = ucwords(str_replace(['-', '_'], ' ', $name));
        $headers = implode('', array_map(fn ($c) => '<th>' . e(ucwords(str_replace('_', ' ', $c))) . '</th>', $columns));

        $rows = '';
        foreach ($data as $i => $row) {
            $bg     = $i % 2 === 0 ? '#ffffff' : '#f9fafb';
            $cells  = implode('', array_map(fn ($c) => '<td>' . e($this->flatten($row[$c] ?? '')) . '</td>', $columns));
            $rows  .= "<tr style=\"background:{$bg}\">{$cells}</tr>";
        }

        $total = count($data);
        $date  = now()->format('d/m/Y H:i');

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111; padding: 20px; }
                h1 { font-size: 14px; font-weight: bold; margin-bottom: 4px; }
                .meta { font-size: 9px; color: #6b7280; margin-bottom: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th { background: #1f2937; color: #fff; padding: 6px 8px; text-align: left; font-size: 9px; }
                td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
            </style>
        </head>
        <body>
            <h1>{$title}</h1>
            <p class="meta">Generado el {$date} &mdash; {$total} registros</p>
            <table>
                <thead><tr>{$headers}</tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </body>
        </html>
        HTML;
    }

    private function flatten(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) ($value ?? '');
    }
}
