<?php

namespace Innertia\PublicApi\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Throw from a public API controller/UseCase to answer with a specific error code:
 *
 *   throw new ApiException('extension_unavailable', 'El anexo no tiene un agente conectado.', 422);
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly mixed $details = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiError::response($this->errorCode, $this->getMessage(), $this->status, $this->details, $this->headers);
    }
}
