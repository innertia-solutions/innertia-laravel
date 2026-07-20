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
        // API JSON por defecto. Excepción: navegación de browser (Accept text/html
        // sin pedir JSON) recibe una página HTML amable — para links de archivos
        // compartibles que un humano abre directo (no encontrado / expirado / etc.).
        // Los responses de archivo/stream OK nunca pasan por aquí (los retorna el
        // controller directamente).
        $exceptions->render(function (\Throwable $e, Request $request): \Symfony\Component\HttpFoundation\Response {
            return static::renderFor($e, $request);
        });
    }

    protected static function renderFor(\Throwable $e, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $json = static::toJson($e);

        if ($request->acceptsHtml() && ! $request->expectsJson()) {
            return static::toHtml($json->getStatusCode());
        }

        return $json;
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

    /**
     * Página HTML mínima y auto-contenida para navegación de browser (links de
     * archivo compartibles que fallan). Sin dependencias ni assets externos.
     */
    protected static function toHtml(int $status): \Illuminate\Http\Response
    {
        [$title, $subtitle] = match (true) {
            $status === 401 => ['Sesión requerida', 'Necesitás iniciar sesión para ver esto.'],
            $status === 403 => ['Enlace no válido', 'Este enlace no es válido o ya expiró.'],
            $status === 404 => ['No encontrado', 'El archivo que buscás no existe o fue movido.'],
            $status === 410 => ['Enlace expirado', 'Este enlace de acceso ya expiró.'],
            $status >= 500  => ['Algo salió mal', 'Ocurrió un error inesperado. Probá de nuevo.'],
            default         => ['Algo salió mal', 'No pudimos completar tu solicitud.'],
        };

        $t = htmlspecialchars($title, ENT_QUOTES);
        $s = htmlspecialchars($subtitle, ENT_QUOTES);

        $html = <<<HTML
        <!doctype html>
        <html lang="es"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$t}</title>
        <style>
          :root { color-scheme: light dark; }
          * { box-sizing: border-box; }
          body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background:#f7f7f8; color:#18181b; padding:24px; }
          @media (prefers-color-scheme: dark){ body{ background:#0b0b0c; color:#e7e7ea; } .card{ background:#141416; border-color:#26262a; } .sub{ color:#a1a1aa; } }
          .card { background:#fff; border:1px solid #ececef; border-radius:16px; padding:40px 36px;
            max-width:420px; width:100%; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,.04); }
          .code { font-size:13px; font-weight:600; letter-spacing:.08em; color:#9ca3af; text-transform:uppercase; }
          h1 { font-size:22px; margin:14px 0 8px; font-weight:650; }
          .sub { font-size:15px; line-height:1.5; color:#6b7280; margin:0; }
          .dot { width:44px; height:44px; border-radius:50%; margin:0 auto 20px;
            display:flex; align-items:center; justify-content:center; background:#f4f4f5; font-size:22px; }
          @media (prefers-color-scheme: dark){ .dot{ background:#1e1e22; } }
        </style></head>
        <body><div class="card">
          <div class="dot">·</div>
          <div class="code">Error {$status}</div>
          <h1>{$t}</h1>
          <p class="sub">{$s}</p>
        </div></body></html>
        HTML;

        return new \Illuminate\Http\Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
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
