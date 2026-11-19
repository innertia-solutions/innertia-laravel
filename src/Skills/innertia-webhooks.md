---
name: innertia-webhooks
description: Use when working with outbound webhooks or the public API — WebhookEvents catalog, Standard Webhooks signing (webhook-id/timestamp/signature, whsec_ secrets, rotation), DomainEvent::webhookPayload(), anti-SSRF, retries/health, integration API keys (pk_live_, scopes, api.key middleware, rate limit, Idempotency-Key), OpenAPI 3.1 (->apiDoc(), #[ApiOperation]), /developers, Routes::manage(). Trigger for "webhook", "Webhook::create", "X-Innertia-Signature", "webhook-signature", "dispatchForEvent", "DispatchWebhookJob", "API pública", "api key", "pk_live", "openapi", "scope".
---

# Innertia — Webhooks y API pública

Guía completa de adopción: `docs/webhooks-api.md` del paquete. Front: `Integrations.ApiKeys`,
`Integrations.Webhooks`, `Integrations.Developers` (innertia-nuxt).

Todo es **opt-in y compatible hacia atrás**: `apikey` (claves `inn_*`), `X-Innertia-Signature`,
`Webhook::create([... 'secret' => …])` y `dispatchForEvent()` siguen funcionando; las suscripciones legacy
reciben el cuerpo antiguo (`payload + _event + _timestamp`).

## Piezas

| Clase | Rol |
|---|---|
| `Innertia\Webhooks\WebhookEvents` | Catálogo: `register(key, description, version, examplePayload, scope)` |
| `Innertia\Webhooks\WebhookService` | `dispatchForEvent(DomainEvent)` (fan-out por clave/`*`/`familia.*`, sede y scopes, `afterCommit`) y `queue()` |
| `Innertia\Webhooks\WebhookDeliverer` | Un intento: anti-SSRF, firmas, POST 10 s sin redirecciones, log, salud |
| `Innertia\Webhooks\Jobs\DispatchWebhookJob` | Cola `webhooks`, 7 intentos (backoff 1 min, 5 min, 30 min, 2 h, 6 h, 12 h), mismo `webhook-id` |
| `Innertia\Webhooks\Signing\StandardWebhookSigner` | `generateSecret()`, `sign()`, `header()`, `verify()` |
| `Innertia\Webhooks\Security\UrlGuard` | Bloquea privadas/loopback/link-local/CGNAT/metadatos (DNS en cada entrega, IP fijada) |
| UseCases `Innertia\Webhooks\UseCases\*` | `CreateWebhook`, `UpdateWebhook`, `RotateWebhookSecret`, `DeleteWebhook`, `SendTestWebhook`, `ResendWebhookDelivery` |
| `Innertia\ApiKeys\ApiScopes` | Registro de scopes (`<recurso>:read|write`, `people:pii`, …) |
| `Innertia\ApiKeys\IntegrationKeys` | Formato `pk_live_…`, SHA-256 + prefijo indexado, `find()` |
| UseCases `Innertia\ApiKeys\UseCases\*` | `CreateIntegrationKey`, `RotateIntegrationKey` (24 h), `RevokeIntegrationKey` |
| Middleware `api.key` | Auth + tenant/sede + scope + rate limit + idempotencia + log + errores normalizados |
| `Innertia\PublicApi\Http\{CursorPage, ApiException, ApiError}` | Paginación por cursor, errores `{error:{code,message,details}}` |
| `Innertia\PublicApi\ActsAsIntegration` | Actor "Integración: <nombre>" en ActivityLogger/EntityHistory |
| `Innertia\PublicApi\Docs\{ApiOperation, ApiSchemas, OpenApiGenerator}` | OpenAPI 3.1 |
| `Innertia\PublicApi\Routes` | `manage()`, `docs()`, `developers()` |

## Emitir un evento

```php
class CallEnded extends DomainEvent
{
    public function key(): DomainEventKey { return CallEvent::Ended; }   // 'call.ended'
    public function channels(): array { return ['webhook']; }

    public function webhookPayload(array $scopes = []): ?array      // vista estable v1, nunca el modelo
    {
        return [
            'id'  => $this->call->id,
            'rut' => in_array('people:pii', $scopes, true) ? $this->call->rut : mask_rut($this->call->rut),
        ];
    }

    public function webhookOrganizationId(): ?string { return (string) $this->call->organization_id; }
}

WebhookEvents::register('call.ended', 'Una llamada terminó', 'v1', ['id' => 'c_1'], scope: null);  // provider boot()
```

Cuerpo v2: `{"type": "call.ended", "timestamp": "…", "data": {…}}`.

## Firma (receptor)

```
webhook-id: msg_…            (dedupe: igual en reintentos/reenvíos)
webhook-timestamp: 1614265330
webhook-signature: v1,<base64(HMAC-SHA256(base64_decode(secret sin "whsec_"), "{id}.{timestamp}.{body}"))>
X-Innertia-Signature: sha256=<hex(HMAC-SHA256(secret completo, body))>
```

```php
StandardWebhookSigner::verify($secret, $id, (int) $ts, $request->getContent(), $request->header('webhook-signature'));
```

Durante una rotación (`RotateWebhookSecret`) `webhook-signature` lleva dos firmas separadas por espacio por 24 h.

## API pública

```php
ApiScopes::register('calls:read', 'Consultar llamadas');

Route::prefix('v1')->middleware('api.key')->group(function () {
    Route::get('calls', [CallsController::class, 'index'])
        ->apiDoc(['summary' => 'Lista llamadas', 'scope' => 'calls:read', 'paginated' => true, 'response' => 'Call']);
});

// controller
return CursorPage::respond($request, Call::query(), fn (Call $c) => ['id' => $c->id]);
throw new ApiException('extension_unavailable', 'El anexo no tiene agente.', 422);
```

Errores: 401 `invalid_api_key`, 403 `insufficient_scope` (+`details.required`), 404 `not_found`,
422 `validation_error`, 409 `idempotency_conflict`, 429 `rate_limited` + `Retry-After`.

## Operación

- Worker de la cola `webhooks` obligatorio en el producto.
- Desarrollo local con receptor en localhost: `INNERTIA_WEBHOOKS_ALLOW_PRIVATE_NETWORKS=true`, `INNERTIA_WEBHOOKS_ALLOW_HTTP=true`.
- Retención 30 días: `innertia:integrations:prune` (agendado 04:30).
- 24 h de fallas continuas → webhook desactivado + aviso `webhook.disabled`; reactivar con `UpdateWebhook(['active' => true])`.

## Anti-patrones

- ❌ Mandar el modelo en el webhook (`payload()` por defecto) — implementar `webhookPayload()`.
- ❌ Emitir antes del commit.
- ❌ Loguear claves/secretos — solo `prefix` / `secret_hint`.
- ❌ Usar `apikey` (legacy `inn_*`) para la API pública nueva — usar `api.key`.
- ❌ Desactivar el anti-SSRF en producción.

## Skills relacionados

- `innertia-events` — DomainEvents y `channels()`
- `innertia-mail` — Notifier (`webhook.disabled`)
