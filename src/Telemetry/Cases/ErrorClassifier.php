<?php

namespace Innertia\Telemetry\Cases;

use Throwable;

class ErrorClassifier
{
    public static function origin(Throwable $e): string
    {
        foreach ($e->getTrace() as $f) {
            $cls = $f['class'] ?? '';
            if (str_starts_with($cls, 'Illuminate\\Queue\\')) return 'queue';
            if (str_starts_with($cls, 'Illuminate\\Console\\Scheduling\\')) return 'schedule';
        }
        try {
            if (! app()->runningInConsole() && app()->bound('request')) return 'http';
        } catch (\Throwable) {}
        try {
            if (app()->runningInConsole()) return 'console';
        } catch (\Throwable) {}
        return 'http';
    }

    public static function category(Throwable $e): string
    {
        return match (true) {
            $e instanceof \Illuminate\Database\QueryException,
            $e instanceof \PDOException                                                 => 'database',
            $e instanceof \Illuminate\Validation\ValidationException                    => 'validation',
            $e instanceof \Illuminate\Auth\AuthenticationException,
            $e instanceof \Illuminate\Auth\Access\AuthorizationException                => 'auth',
            $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => 'http',
            default                                                                     => 'runtime',
        };
    }
}
