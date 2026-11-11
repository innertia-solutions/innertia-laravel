<?php

namespace Innertia\Passkeys\Exceptions;

use Illuminate\Http\JsonResponse;
use Innertia\Passkeys\Support\PasskeyLog;
use RuntimeException;

/**
 * Fallo de verificación: 422 con mensaje genérico (el detalle va solo a Telemetry).
 * Se reporta como warning (no como error): es un fallo por entrada del usuario.
 */
class PasskeyVerificationFailed extends RuntimeException
{
    public function report(): bool
    {
        PasskeyLog::warning('passkey.verification_failed', ['reason' => $this->getMessage()]);

        return true;
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => 'No pudimos verificar la passkey.'], 422);
    }
}
