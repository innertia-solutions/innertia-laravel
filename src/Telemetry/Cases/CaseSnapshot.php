<?php

namespace Innertia\Telemetry\Cases;

use Illuminate\Support\Str;
use Innertia\Telemetry\TelemetryCollector;
use Throwable;

/**
 * Congela todo lo que hace falta para diagnosticar un error como en Sentry:
 * stack completo con código fuente (in-app), contexto del request, cadena de
 * excepciones y breadcrumbs (queries/logs previos). Todo best-effort: nunca lanza.
 */
class CaseSnapshot
{
    private const CONTEXT_LINES   = 5;
    private const MAX_FRAMES       = 50;
    private const MAX_BREADCRUMBS  = 25;
    private const MAX_FILE_BYTES   = 2_000_000;

    public static function build(Throwable $e): array
    {
        return [
            'message'         => $e->getMessage(),
            'exception_class' => get_class($e),
            'stack'           => self::frames($e),
            'previous'        => self::previous($e),
            'request'         => self::request(),
            'breadcrumbs'     => self::breadcrumbs(),
            'captured_at'     => now()->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------------
    // Stack frames
    // -------------------------------------------------------------------------

    private static function frames(Throwable $e): array
    {
        $raw = array_merge(
            [['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '', 'class' => '', 'type' => '']],
            $e->getTrace()
        );

        $frames = [];
        foreach (array_slice($raw, 0, self::MAX_FRAMES) as $f) {
            $file  = $f['file'] ?? null;
            $line  = isset($f['line']) ? (int) $f['line'] : null;
            $inApp = self::isInApp($file);
            $frames[] = [
                'function' => trim(($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '')),
                'file'     => $file ? self::relative($file) : '[internal]',
                'line'     => $line,
                'in_app'   => $inApp,
                'context'  => ($inApp && $file && $line) ? self::sourceContext($file, $line) : null,
            ];
        }
        return $frames;
    }

    private static function isInApp(?string $file): bool
    {
        if (! $file) {
            return false;
        }
        try {
            $base = base_path();
        } catch (\Throwable) {
            return false;
        }
        return str_starts_with($file, $base)
            && ! str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
    }

    private static function relative(string $file): string
    {
        try {
            $base = base_path() . DIRECTORY_SEPARATOR;
        } catch (\Throwable) {
            return $file;
        }
        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private static function sourceContext(string $file, int $line): ?array
    {
        try {
            if (! is_readable($file) || @filesize($file) > self::MAX_FILE_BYTES) {
                return null;
            }
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                return null;
            }
            $start = max(1, $line - self::CONTEXT_LINES);
            $end   = min(count($lines), $line + self::CONTEXT_LINES);
            $out   = [];
            for ($i = $start; $i <= $end; $i++) {
                $out[] = ['n' => $i, 'code' => $lines[$i - 1] ?? '', 'error' => $i === $line];
            }
            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Previous-exception chain
    // -------------------------------------------------------------------------

    private static function previous(Throwable $e): array
    {
        $chain = [];
        $prev  = $e->getPrevious();
        $depth = 0;
        while ($prev && $depth < 5) {
            $chain[] = [
                'class'   => get_class($prev),
                'message' => $prev->getMessage(),
                'file'    => self::relative($prev->getFile()),
                'line'    => $prev->getLine(),
            ];
            $prev = $prev->getPrevious();
            $depth++;
        }
        return $chain;
    }

    // -------------------------------------------------------------------------
    // Request context
    // -------------------------------------------------------------------------

    private static function request(): ?array
    {
        try {
            if (! app()->bound('request')) return null;
            $r = request();
            // Solo capturamos si es un request HTTP real: el request vacío que
            // Laravel tiene bindeado en consola/queue no trae header Host.
            if (! $r instanceof \Illuminate\Http\Request || ! $r->headers->has('host')) return null;

            $sanitizer = new \Innertia\Telemetry\Support\PayloadSanitizer((array) config('innertia.telemetry.redact', []));

            $userId = null;
            try { $userId = optional($r->user())->getAuthIdentifier(); } catch (\Throwable) {}

            $headers = [];
            foreach ($r->headers->all() as $k => $v) { $headers[strtolower($k)] = $v; }
            $headers = $sanitizer->redact($headers);

            $body = $sanitizer->redact($r->all());

            return [
                'method'     => $r->getMethod(),
                'url'        => $r->fullUrl(),
                'route'      => optional($r->route())->getName(),
                'ip'         => $r->ip(),
                'user_id'    => $userId,
                'user_agent' => substr((string) $r->userAgent(), 0, 512),
                'referer'    => substr((string) $r->headers->get('referer', ''), 0, 512) ?: null,
                'query'      => self::cap($sanitizer->redact($r->query())),
                'body'       => self::cap($body),
                'headers'    => self::cap($headers),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cap(array $data, int $maxBytes = 20000): array
    {
        if (strlen((string) json_encode($data)) <= $maxBytes) return $data;
        return ['_truncated' => true];
    }

    // -------------------------------------------------------------------------
    // Breadcrumbs from TelemetryCollector
    // -------------------------------------------------------------------------

    private static function breadcrumbs(): array
    {
        try {
            $events = app(TelemetryCollector::class)->batch();
        } catch (\Throwable) {
            return [];
        }
        $crumbs = [];
        foreach ($events as $ev) {
            if (($ev->type ?? null) === 'exception') {
                continue;
            }
            $crumbs[] = [
                'type'        => $ev->type,
                'at'          => $ev->occurredAt->format(\DateTimeInterface::ATOM),
                'duration_ms' => $ev->durationMs,
                'summary'     => self::summarize($ev),
            ];
        }
        return array_slice(array_reverse($crumbs), 0, self::MAX_BREADCRUMBS);
    }

    private static function summarize(\Innertia\Telemetry\TelemetryEvent $ev): string
    {
        $p = $ev->payload ?? [];
        return match ($ev->type) {
            'query' => Str::limit($p['sql'] ?? $p['query'] ?? 'query', 160),
            'log'   => '[' . ($p['level'] ?? 'log') . '] ' . Str::limit($p['message'] ?? '', 160),
            'mail'  => 'mail: ' . Str::limit($p['subject'] ?? '', 120),
            default => Str::limit((string) json_encode($p), 160),
        };
    }
}
