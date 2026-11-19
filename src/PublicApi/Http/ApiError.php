<?php

namespace Innertia\PublicApi\Http;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Error envelope of the public API: `{"error": {"code", "message", "details"}}` (messages in Spanish).
 */
final class ApiError
{
    /** Default code + message per HTTP status. */
    private const DEFAULTS = [
        400 => ['bad_request', 'La solicitud no es válida.'],
        401 => ['invalid_api_key', 'La clave de API es inválida, fue revocada o venció.'],
        403 => ['forbidden', 'No tienes permiso para esta operación.'],
        404 => ['not_found', 'El recurso no existe.'],
        405 => ['method_not_allowed', 'Método no permitido.'],
        409 => ['conflict', 'La solicitud entra en conflicto con el estado actual.'],
        413 => ['payload_too_large', 'El cuerpo de la solicitud es demasiado grande.'],
        415 => ['unsupported_media_type', 'Tipo de contenido no soportado.'],
        422 => ['validation_error', 'Los datos enviados no son válidos.'],
        429 => ['rate_limited', 'Superaste el límite de solicitudes. Intenta más tarde.'],
        500 => ['server_error', 'Ocurrió un error inesperado.'],
        503 => ['unavailable', 'El servicio no está disponible. Intenta más tarde.'],
    ];

    public static function response(string $code, string $message, int $status, mixed $details = null, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message, 'details' => $details]], $status, $headers);
    }

    public static function forStatus(int $status, mixed $details = null, array $headers = []): JsonResponse
    {
        [$code, $message] = self::DEFAULTS[$status] ?? ($status >= 500 ? self::DEFAULTS[500] : self::DEFAULTS[400]);

        return self::response($code, $message, $status, $details, $headers);
    }

    /**
     * Converts any error response produced inside an api.key route (validation, 404, abort(), exceptions…)
     * to the envelope. Responses already in the envelope are kept.
     */
    public static function normalize(Response $response): Response
    {
        $status = $response->getStatusCode();

        if ($status < 400) {
            return $response;
        }

        $data = $response instanceof JsonResponse ? $response->getData(true) : null;

        if (is_array($data) && is_array($data['error'] ?? null) && isset($data['error']['code'])) {
            return $response;
        }

        $details = null;
        if ($status === 422 && is_array($data) && isset($data['errors'])) {
            $details = $data['errors'];
        }

        $normalized = self::forStatus($status, $details);

        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'X-Trace-Id'] as $header) {
            if ($response->headers->has($header)) {
                $normalized->headers->set($header, $response->headers->get($header));
            }
        }

        return $normalized;
    }
}
