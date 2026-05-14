<?php

namespace Innertia\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
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

        if ($e instanceof NotFoundHttpException) {
            $previous = $e->getPrevious();

            if ($previous instanceof ModelNotFoundException) {
                $model = class_basename($previous->getModel());
                return static::json("{$model} not found.", 'not_found', 404, [], $previous);
            }

            return static::json('Not found.', 'not_found', 404, [], $e);
        }

        // Unexpected — never expose internal details in production
        $message = app()->isLocal() ? $e->getMessage() : 'Server error.';

        return static::json($message, 'server_error', 500, [], $e);
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
