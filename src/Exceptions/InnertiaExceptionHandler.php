<?php

namespace Innertia\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException as SpatieUnauthorizedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Registers consistent JSON error responses for all Innertia apps.
 *
 * Usage in bootstrap/app.php:
 *
 *   ->withExceptions(function (Exceptions $exceptions) {
 *       InnertiaExceptionHandler::register($exceptions);
 *   })
 */
class InnertiaExceptionHandler
{
    public static function register(Exceptions $exceptions): void
    {
        // Esta es una API pura — siempre JSON, sin importar el Accept header.
        // Los responses de archivo/stream ya tienen su propio Content-Type y
        // nunca pasan por aquí (son retornados directamente por el controller).
        $exceptions->render(function (\Throwable $e, Request $request): JsonResponse {
            return static::toJson($e);
        });
    }

    protected static function toJson(\Throwable $e): JsonResponse
    {
        // Our own exception hierarchy
        if ($e instanceof UnprocessableException) {
            return static::json($e->getMessage(), $e->getErrorKey(), $e->getStatusCode(), $e->getErrors(), $e);
        }

        if ($e instanceof InnertiaException) {
            return static::json($e->getMessage(), $e->getErrorKey(), $e->getStatusCode(), [], $e);
        }

        // Laravel / framework exceptions
        if ($e instanceof ValidationException) {
            return static::json('Validation error.', 'validation_error', 422, $e->errors(), $e);
        }

        if ($e instanceof AuthenticationException) {
            return static::json('Unauthenticated.', 'unauthenticated', 401, [], $e);
        }

        if ($e instanceof AuthorizationException || $e instanceof SpatieUnauthorizedException) {
            return static::json('Forbidden.', 'forbidden', 403, [], $e);
        }

        if ($e instanceof ThrottleRequestsException) {
            return static::json('Too many requests.', 'too_many_requests', 429, [], $e);
        }

        if ($e instanceof NotFoundHttpException) {
            $previous = $e->getPrevious();

            if ($previous instanceof ModelNotFoundException) {
                $model = class_basename($previous->getModel());
                return static::json("{$model} not found.", 'not_found', 404, [], $previous);
            }

            return static::json('Not found.', 'not_found', 404, [], $e);
        }

        // Cualquier HttpException con status explícito (abort(401/403/404/422/…)).
        // Debe respetar su código y mensaje, no colapsar a 500 server_error.
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $status  = $e->getStatusCode();
            $message = $e->getMessage() !== '' ? $e->getMessage() : static::defaultMessageFor($status);

            return static::json($message, static::errorKeyFor($status), $status, [], $e);
        }

        // Unexpected — never expose internal details in production
        $message = app()->isLocal() ? $e->getMessage() : 'Server error.';

        return static::json($message, 'server_error', 500, [], $e);
    }

    private static function errorKeyFor(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            422 => 'validation_error',
            429 => 'too_many_requests',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };
    }

    private static function defaultMessageFor(int $status): string
    {
        return match ($status) {
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            404 => 'Not found.',
            409 => 'Conflict.',
            429 => 'Too many requests.',
            default => 'Request failed.',
        };
    }

    private static function json(
        string $message,
        string $error,
        int $status,
        array $errors,
        \Throwable $e,
    ): JsonResponse {
        $body = [
            'message' => $message,
            'error'   => $error,
            'errors'  => $errors,
        ];

        if (app()->isLocal()) {
            $body['trace'] = collect($e->getTrace())
                ->take(10)
                ->map(fn ($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'))
                ->values()
                ->all();
        }

        return new JsonResponse($body, $status);
    }
}
