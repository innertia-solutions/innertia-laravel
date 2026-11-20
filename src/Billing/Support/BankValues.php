<?php

namespace Innertia\Billing\Support;

/** Parsing of bank statement cells (Chilean formats) and text normalization for matching. */
final class BankValues
{
    /**
     * "1.234.567" / "$ 1.234" / "1,234,567" / "1.234,50" / "(1.500)" / "1500-" / 1234.0 → integer pesos.
     * null when empty or not a number.
     */
    public static function amount(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return Money::round((float) $value);
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $negative = str_starts_with($raw, '(') || str_ends_with($raw, '-') || str_starts_with($raw, '-');
        $clean = preg_replace('/[^\d.,]/', '', $raw);
        if ($clean === '' || ! preg_match('/\d/', $clean)) {
            return null;
        }

        $dot = strrpos($clean, '.');
        $comma = strrpos($clean, ',');
        if ($dot !== false && $comma !== false) {
            // The last separator is the decimal one.
            $decimal = $dot > $comma ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';
            $clean = str_replace([$thousands, $decimal], ['', '.'], $clean);
        } elseif ($dot !== false) {
            $clean = preg_match('/^\d{1,3}(\.\d{3})+$/', $clean) ? str_replace('.', '', $clean) : $clean;
        } elseif ($comma !== false) {
            $clean = preg_match('/^\d{1,3}(,\d{3})+$/', $clean) ? str_replace(',', '', $clean) : str_replace(',', '.', $clean);
        }

        if (! is_numeric($clean)) {
            return null;
        }
        $amount = Money::round((float) $clean);

        return $negative ? -$amount : $amount;
    }

    /** Date cell → 'Y-m-d' (DateTime, Excel serial, d/m/Y, d-m-Y, d.m.Y, Y-m-d, d/m/y). */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', trim($value)))) {
            $serial = (float) $value;
            if ($serial > 20000 && $serial < 80000) {
                return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $serial . ' days')->format('Y-m-d');
            }

            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $raw = preg_split('/\s+/', $raw)[0];
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $raw, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Lowercase ASCII, single spaces (accents removed). */
    public static function text(?string $value): string
    {
        $value = (string) $value;
        $ascii = \Illuminate\Support\Str::ascii($value);

        return trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^A-Za-z0-9 ]/', ' ', $ascii))));
    }

    /** RUT normalized to digits + verifier (K upper), no dots/dash: "76.111.111-1" → "761111111". */
    public static function rut(?string $value): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', (string) $value));
    }

    /** RUTs written in a free text (12.345.678-9, 12345678-9, 123456789). */
    public static function rutsIn(?string $text): array
    {
        preg_match_all('/\b\d{1,2}\.?\d{3}\.?\d{3}-?[\dkK]\b/', (string) $text, $m);

        return array_values(array_unique(array_map([self::class, 'rut'], $m[0])));
    }
}
