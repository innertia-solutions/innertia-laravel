# Webhooks y API pública — guía de adopción (innertia-laravel)

Motor de **API pública con API keys por espacio** (scopes, sede, vencimiento, rotación, rate limit,
idempotencia, log) y **webhooks salientes v2** (catálogo de eventos, firma *Standard Webhooks*, anti-SSRF,
reintentos ~21 h, salud con desactivación y aviso), con **OpenAPI 3.1 generado** y página `/developers`.

Todo es **aditivo y opt-in**: nada se expone hasta que el producto monta las rutas y usa el middleware
`api.key`. Lo existente (`apikey`, `X-Innertia-Signature`, `Webhook::create`, `dispatchForEvent`) sigue
funcionando igual. Front: componentes `Integrations.ApiKeys`, `Integrations.Webhooks` e
`Integrations.Developers` de innertia-nuxt (ver `docs/webhooks-api.md` de esa lib).

## 1. Migración

`php artisan migrate` (modos app/saas/open; la carga la lib). Es idempotente y solo agrega:

| Tabla | Cambio |
|---|---|
| `webhooks` | `organization_id`, `signing_secret` (cifrado), `previous_secret` (cifrado) + `previous_secret_expires_at`, `secret_hint`, `scopes`, `created_by`, `disabled_at`, `disabled_reason`, `failing_since`, `last_success_at`, `last_failure_at` |
| `webhook_logs` | `message_id` (= `webhook-id`), `status`, `duration_ms`, `error`, `request_headers`, `is_test`, `trace_id`, `next_retry_at` |
| `api_keys` | `prefix` (indexado), `organization_id`, `last_used_ip`, `created_by`, `rotated_to_id`, `rotated_at` |
| `api_request_logs` | nueva: método, ruta, estado, ms, prefijo de la clave, IP, `trace_id` |
| `api_idempotency_keys` | nueva: respuestas guardadas por `Idempotency-Key` (24 h) |

Retención: `innertia:integrations:prune` (agendado 04:30, 30 días por defecto).

## 2. Declarar scopes, esquemas y eventos (provider del producto)

```php
use Innertia\ApiKeys\ApiScopes;
use Innertia\PublicApi\Docs\ApiSchemas;
use Innertia\Webhooks\WebhookEvents;

public function boot(): void
{
    ApiScopes::register('calls:read', 'Consultar llamadas');
    ApiScopes::register('calls:write', 'Iniciar llamadas (click-to-call)');
    ApiScopes::register('people:pii', 'Ver RUT y datos personales completos');
    ApiScopes::register('recordings:read', 'Descargar grabaciones');

    ApiSchemas::register('Call', [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'string'], 'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']]],
        'required' => ['id'],
    ]);

    WebhookEvents::register('call.ended', 'Una llamada terminó', 'v1', ['id' => 'c_123', 'duration' => 42]);
    WebhookEvents::register('call.recording_ready', 'Grabación disponible', 'v1', ['id' => 'c_123', 'url' => 'https://…'], scope: 'recordings:read');
}
```

También se aceptan scopes en `config('innertia.public_api.scopes')` (`['calls:read' => 'Consultar llamadas']`).

## 3. Rutas

```php
// routes/api.private.php — administración (pantalla Sistema › Integraciones)
Route::middleware(['auth:api', 'tenant.require', 'permission:settings.manage'])->group(function () {
    \Innertia\PublicApi\Routes::manage();          // prefijo 'integrations'
});

// routes/api.php — documentación pública
\Innertia\PublicApi\Routes::docs();                // GET /api/v1/openapi.json
\Innertia\PublicApi\Routes::developers();          // GET /api/developers (Scalar 1.76.4 desde jsDelivr)

// routes/api.clients.php — endpoints v1
Route::prefix('v1')->middleware('api.key')->group(function () {
    Route::get('calls', [CallsController::class, 'index'])->apiDoc([
        'summary' => 'Lista llamadas', 'scope' => 'calls:read', 'tags' => ['Llamadas'], 'paginated' => true,
        'query' => ['direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']]],
        'response' => 'Call',
    ]);
    Route::post('calls', [CallsController::class, 'store'])->apiDoc([
        'summary' => 'Click-to-call', 'scope' => 'calls:write', 'body' => [...], 'response' => 'Call',
        'errors' => [422 => 'Anexo sin agente (`extension_unavailable`).'],
    ]);
});
```

`manage()` expone (todo sobre el espacio activo):

| Método | Ruta | Respuesta |
|---|---|---|
| GET | `integrations/api-keys` | `{data: ApiKey[]}` |
| GET | `integrations/api-keys/scopes` | `{data: [{key, description, group}]}` |
| POST | `integrations/api-keys` `{name, scopes[], organization_id?, expires_at?}` | 201 `{data, secret}` (la clave se muestra una vez) |
| POST | `integrations/api-keys/{id}/rotate` | `{data, secret, previous: {id, expires_at}}` (la anterior vale 24 h) |
| DELETE | `integrations/api-keys/{id}` | `{data}` revocada |
| GET | `integrations/api-keys/{id}/requests?cursor&limit` | log de requests |
| GET/POST | `integrations/webhooks` | lista / crear `{url, events[], description?, organization_id?, scopes[]?, active?}` → `{data, secret}` |
| GET | `integrations/webhooks/events` | catálogo de eventos |
| GET/PATCH/DELETE | `integrations/webhooks/{id}` | detalle / editar (reactivar limpia la salud) / eliminar |
| POST | `integrations/webhooks/{id}/rotate-secret` | `{data, secret}` (doble firma 24 h) |
| POST | `integrations/webhooks/{id}/test` | 202 envía `webhook.test` |
| GET | `integrations/webhooks/{id}/logs?cursor&limit&status` | entregas |
| GET | `integrations/webhooks/{id}/logs/{log}` | detalle con payload, headers y respuesta |
| POST | `integrations/webhooks/{id}/logs/{log}/resend` | 202 reenvía (mismo `webhook-id`) |

## 4. Descriptores de endpoint

Dos formas equivalentes (mismas claves): macro `->apiDoc([...])` (sobrevive a `route:cache`) o atributo
`#[\Innertia\PublicApi\Docs\ApiOperation(...)]` en el método del controller.

| Clave | Uso |
|---|---|
| `summary`, `description`, `tags`, `operationId` | documentación |
| `scope` | **exigido por `api.key`** (403 `insufficient_scope`) y publicado como `x-scope` |
| `query`, `path` | parámetros (`['nombre' => ['type' => 'string', 'description' => …, 'required' => true]]`) |
| `body`, `response` | `'Call'` (componente), `'Call[]'` o JSON Schema inline; la respuesta se envuelve en `{data}` |
| `paginated` | agrega `cursor`, `limit`, `updated_since` y envuelve en `{data[], meta}` |
| `status`, `errors` | código de éxito (POST → 201) y errores extra `[422 => '…']` |

## 5. Convenciones del middleware `api.key`

- Clave en `Authorization: Bearer pk_live_…` o `X-Api-Key`. Formato `pk_live_` + 40 alfanuméricos
  (`pk_test_` con `INNERTIA_PUBLIC_API_KEY_ENV=test`). Se guarda solo SHA-256 + prefijo de 16 caracteres.
- Activa el tenant de la clave (y la sede si Organizations está activo) solo durante la request.
- Scope: parámetros (`api.key:calls:write`) ∪ `scope` del descriptor. Anidar grupo + ruta funciona.
- Rate limit: 120/min por clave y 600/min por espacio (`innertia.public_api.rate_limit`), headers
  `X-RateLimit-Limit/Remaining/Reset`, 429 `rate_limited` + `Retry-After`.
- `Idempotency-Key` en POST/PUT/PATCH/DELETE: mismo cuerpo → misma respuesta (`Idempotent-Replayed: true`),
  otro cuerpo → 409 `idempotency_conflict`; 5xx no se guarda.
- Errores siempre `{"error": {"code", "message", "details"}}` en español: `invalid_api_key` (401),
  `insufficient_scope` (403, `details.required`), `not_found` (404), `validation_error` (422 con
  `details` = errores por campo), `rate_limited` (429). Errores propios:
  `throw new \Innertia\PublicApi\Http\ApiException('extension_unavailable', 'El anexo no tiene agente.', 422);`
- Paginación: `return \Innertia\PublicApi\Http\CursorPage::respond($request, Call::query(), fn ($c) => [...]);`
  (`limit` ≤ 100, `updated_since` sobre `updated_at`).
- Actor: `\Innertia\PublicApi\ActsAsIntegration::current()/label()`; `ActivityLogger` guarda
  `metadata.actor = {type: integration, api_key_id, name, label}` y `EntityHistory` usa
  "Integración: <nombre>" como motivo cuando no hay usuario. En jobs: `ActsAsIntegration::run($key, fn () => …)`.
- Log de requests en `api_request_logs` (nunca la clave, solo el prefijo).

## 6. Eventos de webhook desde un DomainEvent

```php
class CallEnded extends DomainEvent
{
    public function channels(): array { return ['webhook']; }

    // Vista estable v1 (nunca el modelo). $scopes = scopes otorgados a la suscripción.
    public function webhookPayload(array $scopes = []): ?array
    {
        return [
            'id'        => $this->call->id,
            'direction' => $this->call->direction,
            'recording' => in_array('recordings:read', $scopes, true) ? $this->signedRecordingUrl() : null,
        ];
    }

    // Sede del evento: las suscripciones limitadas a una sede solo reciben las suyas.
    public function webhookOrganizationId(): ?string { return (string) $this->call->organization_id; }
}
```

Emitir **después del commit** (`DB::afterCommit` o desde el UseCase al final); la lib además encola con
`afterCommit()`. Suscripciones: clave exacta, `*` o familia `call.*`.

### Entrega

- POST JSON `{"type": "call.ended", "timestamp": "…", "data": {…}}` (suscripciones legacy reciben el cuerpo antiguo).
- Headers: `webhook-id` (idéntico en reintentos y reenvíos), `webhook-timestamp`, `webhook-signature`
  (`v1,<base64(HMAC-SHA256(clave, "{id}.{timestamp}.{body}"))>`; dos firmas separadas por espacio durante
  una rotación), `X-Innertia-Event`, `X-Innertia-Signature: sha256=<hex hmac del body con el secreto completo>`,
  `X-Innertia-Delivery`.
- Secreto `whsec_<base64>`: la clave HMAC es la parte base64 decodificada (compatible con las librerías
  Standard Webhooks/Svix). Verificación: `\Innertia\Webhooks\Signing\StandardWebhookSigner::verify(...)`.
- Cola `webhooks` (**el producto debe correr un worker de esa cola**), timeout 10 s, sin redirecciones,
  respuesta truncada a 64 KB. Reintentos: 1 min, 5 min, 30 min, 2 h, 6 h, 12 h.
- Anti-SSRF: DNS en cada entrega, conexión fijada a la IP validada; se bloquean privadas, loopback,
  link-local, CGNAT, multicast, reservadas, documentación y metadatos. En desarrollo local:
  `INNERTIA_WEBHOOKS_ALLOW_PRIVATE_NETWORKS=true` y `INNERTIA_WEBHOOKS_ALLOW_HTTP=true`.
- Salud: 24 h de fallas continuas → se desactiva y se envía el aviso `webhook.disabled` (Notifier) a
  quienes tienen `settings.manage` y al creador. El tipo de aviso se registra al montar `Routes::manage()`.
  `innertia.webhooks.manage_url` define el enlace del aviso.

## 7. Configuración (`config('innertia.public_api')` / `config('innertia.webhooks')`)

Ver los bloques `public_api` y `webhooks` de `config/innertia.php` (título/versión/URL del OpenAPI, entorno
de las claves, rate limits, horas de idempotencia y de convivencia en rotaciones, retención, backoff,
horas para desactivar, permiso a avisar, URL de Scalar).

## 8. Correcciones incluidas (compatibles)

- `UseCase::delay()` ya no entra en recursión infinita (guarda el delay en la propiedad que lee el bus).
- Con cola `sync`, un UseCase restaura el tenant previo al terminar (antes lo dejaba en null).
- `EmailLogging::listen()` es idempotente por dispatcher; `EmailLogging::reset()` ya no es necesario.
- `ApiKey::revoke()` y `touchLastUsed()` guardaban columnas no `fillable` (no tenían efecto).
- `DispatchWebhookJob` redeclaraba `$queue` tipado (error fatal al cargar la clase): corregido.
