<?php

namespace Innertia\Billing\Support;

use Innertia\Exceptions\ConflictException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads a bank statement (cartola) exported as CSV or XLSX into rows, finds the header row and maps
 * the columns chosen by the user:
 *
 *   mapping: { date, amount | credit (+ debit), description?, reference?, tax_id?, name? } → header names
 *
 * CSV: delimiter detected (; , tab), Windows-1252/Latin-1 converted to UTF-8. Banks add title rows
 * before the table: the header row is the first one that contains every mapped header (or options.header_row).
 */
final class BankStatementReader
{
    public const FIELDS = ['date', 'amount', 'credit', 'debit', 'description', 'reference', 'tax_id', 'name'];

    private const GUESS = [
        'date'        => ['fecha contable', 'fecha', 'date'],
        'credit'      => ['abonos', 'abono', 'depositos', 'deposito', 'haber', 'ingresos', 'ingreso', 'creditos', 'credito'],
        'debit'       => ['cargos', 'cargo', 'debe', 'egresos', 'egreso', 'giros', 'debitos', 'debito'],
        'amount'      => ['monto', 'importe', 'valor', 'amount'],
        'description' => ['descripcion', 'glosa', 'detalle', 'concepto', 'movimiento', 'description'],
        'reference'   => ['n documento', 'documento', 'n operacion', 'operacion', 'referencia', 'comprobante', 'serial'],
        'tax_id'      => ['rut origen', 'rut', 'run'],
        'name'        => ['nombre origen', 'nombre', 'ordenante', 'remitente', 'origen'],
    ];

    /** @return array<int, array<int, mixed>> every non-empty row */
    public static function rows(string $content, string $format): array
    {
        return $format === 'xlsx' ? self::xlsxRows($content) : self::csvRows($content);
    }

    public static function format(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, ['xls', 'ods'], true)) {
            throw new ConflictException('Guarda la cartola como .xlsx o .csv para importarla.');
        }

        return $ext === 'xlsx' ? 'xlsx' : 'csv';
    }

    /**
     * Header row (0-based) with its cells; with no mapping, the row that looks most like a header.
     *
     * @return array{index: int, headers: string[]}
     */
    public static function header(array $rows, array $mapping = [], ?int $headerRow = null): array
    {
        if ($headerRow !== null && isset($rows[$headerRow - 1])) {
            return ['index' => $headerRow - 1, 'headers' => self::cells($rows[$headerRow - 1])];
        }

        $wanted = array_values(array_filter(array_map(fn ($h) => BankValues::text((string) $h), array_intersect_key($mapping, array_flip(self::FIELDS)))));
        $best = ['index' => 0, 'score' => -1];
        foreach (array_slice($rows, 0, 40, true) as $i => $row) {
            $cells = array_map(fn ($c) => BankValues::text((string) self::scalar($c)), $row);
            if ($wanted !== []) {
                if (array_diff($wanted, $cells) === []) {
                    return ['index' => $i, 'headers' => self::cells($row)];
                }
                continue;
            }
            $score = 0;
            foreach (self::GUESS as $words) {
                foreach ($cells as $cell) {
                    if ($cell !== '' && in_array($cell, $words, true)) {
                        $score++;
                        break;
                    }
                }
            }
            if ($score > $best['score']) {
                $best = ['index' => $i, 'score' => $score];
            }
        }

        if ($wanted !== []) {
            throw new ConflictException('No se encontró la fila de encabezados con las columnas elegidas.');
        }

        return ['index' => $best['index'], 'headers' => self::cells($rows[$best['index']] ?? [])];
    }

    /** Suggested mapping by header names (Spanish bank exports). */
    public static function guessMapping(array $headers): array
    {
        $normalized = array_map(fn ($h) => BankValues::text($h), $headers);
        $out = [];
        $used = [];
        foreach (self::GUESS as $field => $words) {
            foreach ($words as $word) {
                foreach ($normalized as $i => $cell) {
                    if (! isset($used[$i]) && ($cell === $word || str_starts_with($cell, $word . ' '))) {
                        $out[$field] = $headers[$i];
                        $used[$i] = true;
                        break 2;
                    }
                }
            }
        }
        if (isset($out['credit'], $out['amount'])) {
            unset($out['amount']);
        }

        return $out;
    }

    /**
     * Data rows as associative arrays field → raw value (+ 'raw' header → value).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function mapped(array $rows, array $mapping, ?int $headerRow = null): array
    {
        if (empty($mapping['date']) || (empty($mapping['amount']) && empty($mapping['credit']))) {
            throw new ConflictException('Indica al menos la columna de fecha y la de monto (o abonos).');
        }
        $header = self::header($rows, $mapping, $headerRow);
        $index = [];
        foreach ($header['headers'] as $i => $name) {
            $index[BankValues::text($name)] ??= $i;
        }

        $columns = [];
        foreach (self::FIELDS as $field) {
            if (! empty($mapping[$field])) {
                $key = BankValues::text((string) $mapping[$field]);
                if (! isset($index[$key])) {
                    throw new ConflictException("La columna \"{$mapping[$field]}\" no está en la cartola.");
                }
                $columns[$field] = $index[$key];
            }
        }

        $out = [];
        foreach (array_slice($rows, $header['index'] + 1) as $row) {
            $item = [];
            foreach ($columns as $field => $i) {
                $item[$field] = self::scalar($row[$i] ?? null);
            }
            $raw = [];
            foreach ($header['headers'] as $i => $name) {
                if ($name !== '') {
                    $raw[$name] = self::cellString($row[$i] ?? null);
                }
            }
            $item['raw'] = $raw;
            $out[] = $item;
        }

        return $out;
    }

    private static function csvRows(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\n|\r/', $content);
        $sample = implode("\n", array_slice($lines, 0, 30));
        $delimiter = collect([';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")])
            ->sortDesc()->keys()->first();

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line, $delimiter, '"', '');
            if (array_filter($row, fn ($c) => trim((string) $c) !== '') !== []) {
                $rows[] = array_map(fn ($c) => trim((string) $c), $row);
            }
        }

        return $rows;
    }

    private static function xlsxRows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cartola_') . '.xlsx';
        file_put_contents($path, $content);
        $rows = [];
        $reader = new XlsxReader;

        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    if (array_filter($values, fn ($c) => $c !== null && trim(self::cellString($c)) !== '') !== []) {
                        $rows[] = $values;
                    }
                }
                break; // first sheet only
            }
        } catch (\Throwable $e) {
            throw new ConflictException('No se pudo leer el archivo Excel: ' . $e->getMessage());
        } finally {
            $reader->close();
            @unlink($path);
        }

        return $rows;
    }

    private static function cells(array $row): array
    {
        return array_map(fn ($c) => trim(self::cellString($c)), $row);
    }

    private static function scalar(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    public static function cellString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');
    }
}
