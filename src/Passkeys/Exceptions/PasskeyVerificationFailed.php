<?php

namespace Innertia\Passkeys\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Fallo de verificación: 422 con mensaje genérico (el detalle va solo a Telemetry). */
class PasskeyVerificationFailed extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => 'No pudimos verificar la passkey.'], 422);
    }
}
