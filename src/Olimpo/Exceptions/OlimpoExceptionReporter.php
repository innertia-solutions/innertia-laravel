<?php

namespace Innertia\Olimpo\Exceptions;

use Illuminate\Support\Facades\Http;
use Throwable;

class OlimpoExceptionReporter
{
    public static function report(Throwable $e, array $context = []): void
    {
        $url = config('olimpo.url');
        $key = config('olimpo.key');

        if (!$url || !$key) return;

        // No reportar si está en la lista de excepciones ignoradas
        $except = config('olimpo.except', []);
        foreach ($except as $class) {
            if ($e instanceof $class) return;
        }

        $payload = [
            'exception'   => get_class($e),
            'message'     => $e->getMessage(),
            'file'        => $e->getFile(),
            'line'        => $e->getLine(),
            'trace'       => static::formatTrace($e),
            'previous'    => $e->getPrevious() ? get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage() : null,
            'context'     => $context,
            'occurred_at' => now()->toISOString(),
        ];

        // defer() → se ejecuta después de enviar la respuesta HTTP
        // En workers (sin respuesta) se ejecuta inmediatamente
        if (function_exists('defer')) {
            defer(fn () => static::send($url, $key, $payload));
        } else {
            static::send($url, $key, $payload);
        }
    }

    private static function send(string $url, string $key, array $payload): void
    {
        try {
            Http::timeout(3)
                ->withHeader('X-Api-Key', $key)
                ->post(rtrim($url, '/') . '/exceptions', $payload);
        } catch (\Throwable) {
            // Silencioso — nunca romper la app por fallar al reportar
        }
    }

    private static function formatTrace(Throwable $e): array
    {
        return collect($e->getTrace())
            ->take(30)
            ->map(fn ($frame) => [
                'file'     => $frame['file'] ?? null,
                'line'     => $frame['line'] ?? null,
                'class'    => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
            ])
            ->toArray();
    }
}
