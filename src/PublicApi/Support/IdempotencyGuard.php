<?php

namespace Innertia\PublicApi\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Innertia\ApiKeys\Models\ApiKey;
use Innertia\PublicApi\Http\ApiError;
use Innertia\PublicApi\Models\IdempotencyKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` for writes (POST/PUT/PATCH/DELETE), per API key, 24 h:
 *   same key + same method/path/body → the stored response is replayed (header Idempotent-Replayed: true)
 *   same key + different request     → 409 idempotency_conflict
 *   same key while the first is still running → 409 idempotency_in_progress
 * 5xx responses are not stored (the client may retry).
 */
class IdempotencyGuard
{
    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, ApiKey $key, Closure $next): Response
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if ($idempotencyKey === '' || ! in_array($request->method(), self::METHODS, true) || ! IntegrationsSchema::idempotency()) {
            return $next();
        }

        if (mb_strlen($idempotencyKey) > 191) {
            return ApiError::response('validation_error', 'El header Idempotency-Key no puede superar 191 caracteres.', 422);
        }

        $hash = hash('sha256', $request->method() . '|' . $request->path() . '|' . $request->getContent());

        IdempotencyKey::query()->where('api_key_id', $key->id)->where('idempotency_key', $idempotencyKey)
            ->where('expires_at', '<=', now())->delete();

        $existing = IdempotencyKey::query()->where('api_key_id', $key->id)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $this->replay($existing, $hash);
        }

        try {
            $record = IdempotencyKey::create([
                'tenant_id'       => $key->tenant_id ?? null,
                'api_key_id'      => $key->id,
                'idempotency_key' => $idempotencyKey,
                'method'          => $request->method(),
                'path'            => mb_substr($request->path(), 0, 255),
                'request_hash'    => $hash,
                'created_at'      => now(),
                'expires_at'      => now()->addHours((int) config('innertia.public_api.idempotency_hours', 24)),
            ]);
        } catch (QueryException) {
            $existing = IdempotencyKey::query()->where('api_key_id', $key->id)->where('idempotency_key', $idempotencyKey)->first();

            return $existing ? $this->replay($existing, $hash) : ApiError::response('idempotency_in_progress', 'Otra solicitud con esta Idempotency-Key está en curso.', 409);
        }

        $response = $next();

        if ($response->getStatusCode() >= 500) {
            $record->delete();

            return $response;
        }

        $record->update([
            'response_status'  => $response->getStatusCode(),
            'response_body'    => (string) $response->getContent(),
            'response_headers' => ['Content-Type' => $response->headers->get('Content-Type', 'application/json')],
        ]);

        return $response;
    }

    private function replay(IdempotencyKey $existing, string $hash): Response
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            return ApiError::response('idempotency_conflict', 'La Idempotency-Key ya se usó con una solicitud distinta.', 409);
        }

        if ($existing->response_status === null) {
            return ApiError::response('idempotency_in_progress', 'Otra solicitud con esta Idempotency-Key está en curso.', 409);
        }

        return new IlluminateResponse((string) $existing->response_body, (int) $existing->response_status, ($existing->response_headers ?? []) + ['Idempotent-Replayed' => 'true']);
    }
}
