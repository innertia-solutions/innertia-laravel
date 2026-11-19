<?php

namespace Innertia\PublicApi\Docs;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Innertia\ApiKeys\ApiScopes;
use Innertia\Webhooks\WebhookEvents;

/**
 * Builds the OpenAPI 3.1 document from the route descriptors (->apiDoc() / #[ApiOperation]), the registered
 * schemas (ApiSchemas) and the webhook event catalog (WebhookEvents → top-level `webhooks`).
 */
class OpenApiGenerator
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly Router $router) {}

    public function generate(): array
    {
        $paths = [];
        $tags = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $descriptor = RouteDescriptor::for($route);
            if ($descriptor === null) {
                continue;
            }

            $path = '/' . ltrim(preg_replace('/\{(\w+)\?\}/', '{$1}', $route->uri()), '/');

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][strtolower($method)] = $this->operation($route, $method, $descriptor);

                foreach ((array) ($descriptor['tags'] ?? []) as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        ksort($paths);

        $document = [
            'openapi'  => '3.1.0',
            'info'     => array_filter([
                'title'       => (string) (config('innertia.public_api.title') ?: config('app.name', 'API') . ' API'),
                'version'     => (string) (config('innertia.public_api.version') ?: '1.0.0'),
                'description' => config('innertia.public_api.description'),
            ], fn ($v) => $v !== null && $v !== ''),
            'servers'  => [['url' => rtrim((string) (config('innertia.public_api.server_url') ?: config('app.url', 'http://localhost')), '/')]],
            'security' => [['bearerAuth' => []], ['apiKeyHeader' => []]],
            'tags'     => array_map(fn ($t) => ['name' => $t], array_keys($tags)),
            'paths'    => $paths === [] ? new \stdClass : $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth'   => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Clave de API (pk_live_…) en `Authorization: Bearer`.'],
                    'apiKeyHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
                'schemas' => $this->schemas(),
            ],
        ];

        $webhooks = $this->webhooks();
        if ($webhooks !== []) {
            $document['webhooks'] = $webhooks;
            $document['tags'][] = ['name' => 'Webhooks', 'description' => 'Eventos enviados a tus suscripciones (firma Standard Webhooks).'];
        }

        if ($document['tags'] === []) {
            unset($document['tags']);
        }

        $scopes = ApiScopes::all();
        if ($scopes !== []) {
            $document['info']['x-scopes'] = array_values($scopes);
        }

        return $document;
    }

    private function operation(Route $route, string $method, array $d): array
    {
        $parameters = [];

        preg_match_all('/\{(\w+)\??\}/', $route->uri(), $matches);
        foreach ($matches[1] as $name) {
            $spec = (array) (($d['path'] ?? [])[$name] ?? []);
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true]
                + $this->parameterSpec($spec + ['type' => 'string']);
        }

        foreach ((array) ($d['query'] ?? []) as $name => $spec) {
            $spec = is_string($spec) ? ['type' => $spec] : (array) $spec;
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => (bool) ($spec['required'] ?? false)]
                + $this->parameterSpec($spec);
        }

        if (! empty($d['paginated'])) {
            $parameters[] = ['name' => 'cursor', 'in' => 'query', 'required' => false, 'description' => 'Cursor de la página siguiente (`meta.next_cursor`).', 'schema' => ['type' => 'string']];
            $parameters[] = ['name' => 'limit', 'in' => 'query', 'required' => false, 'description' => 'Tamaño de página (máximo 100).', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]];
            $parameters[] = ['name' => 'updated_since', 'in' => 'query', 'required' => false, 'description' => 'Solo registros modificados desde esta fecha (ISO 8601).', 'schema' => ['type' => 'string', 'format' => 'date-time']];
        }

        if (in_array($method, self::WRITE_METHODS, true)) {
            $parameters[] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => 'Reintento seguro: misma clave y mismo cuerpo → misma respuesta (24 h); otro cuerpo → 409.', 'schema' => ['type' => 'string', 'maxLength' => 191]];
        }

        $scope = $d['scope'] ?? null;
        $description = trim(($d['description'] ?? '') . ($scope ? "\n\nScope requerido: `{$scope}`." : ''));
        $status = (string) ($d['status'] ?? ($method === 'POST' ? 201 : 200));

        $responseSchema = $this->schema($d['response'] ?? null);
        if (! empty($d['paginated'])) {
            $responseSchema = [
                'type'       => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => $responseSchema ?? ['type' => 'object']],
                    'meta' => ['$ref' => '#/components/schemas/CursorMeta'],
                ],
                'required' => ['data', 'meta'],
            ];
        } elseif ($responseSchema !== null) {
            $responseSchema = ['type' => 'object', 'properties' => ['data' => $responseSchema], 'required' => ['data']];
        }

        $responses = [
            $status => array_filter([
                'description' => 'OK',
                'content'     => $responseSchema ? ['application/json' => ['schema' => $responseSchema]] : null,
            ]),
        ];

        $errors = [401 => 'Clave inválida, revocada o vencida (`invalid_api_key`).', 403 => 'Falta el scope requerido (`insufficient_scope`).', 429 => 'Límite de solicitudes (`rate_limited`, header `Retry-After`).'];
        if ($matches[1] !== []) {
            $errors[404] = 'No existe o pertenece a otro espacio/sede (`not_found`).';
        }
        if (in_array($method, self::WRITE_METHODS, true)) {
            $errors[409] = 'Idempotency-Key reutilizada con otro cuerpo (`idempotency_conflict`).';
            $errors[422] = 'Error de validación (`validation_error`).';
        }
        foreach ((array) ($d['errors'] ?? []) as $code => $text) {
            $errors[(int) $code] = (string) $text;
        }
        ksort($errors);

        foreach ($errors as $code => $text) {
            $responses[(string) $code] = [
                'description' => $text,
                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ];
        }

        $operation = [
            'operationId' => $d['operationId'] ?? ($route->getName() ? Str::camel(str_replace(['.', '-'], '_', $route->getName())) : Str::camel(strtolower($method) . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $route->uri()))),
            'summary'     => (string) ($d['summary'] ?? $route->uri()),
            'description' => $description !== '' ? $description : null,
            'tags'        => array_values((array) ($d['tags'] ?? [])) ?: null,
            'parameters'  => $parameters ?: null,
            'requestBody' => ($body = $this->schema($d['body'] ?? null)) !== null
                ? ['required' => true, 'content' => ['application/json' => ['schema' => $body]]]
                : null,
            'responses'   => $responses,
            'x-scope'     => $scope,
        ];

        return array_filter($operation, fn ($v) => $v !== null);
    }

    private function parameterSpec(array $spec): array
    {
        $description = $spec['description'] ?? null;
        unset($spec['description'], $spec['required']);

        return array_filter(['description' => $description, 'schema' => $spec], fn ($v) => $v !== null);
    }

    /** 'Call' → $ref, 'Call[]' → array of $ref, array → inline schema, null → null. */
    private function schema(string|array|null $schema): ?array
    {
        if ($schema === null) {
            return null;
        }

        if (is_array($schema)) {
            return $schema;
        }

        if (str_ends_with($schema, '[]')) {
            return ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . substr($schema, 0, -2)]];
        }

        return ['$ref' => '#/components/schemas/' . $schema];
    }

    private function schemas(): array
    {
        return array_merge([
            'Error' => [
                'type'       => 'object',
                'properties' => [
                    'error' => [
                        'type'       => 'object',
                        'properties' => [
                            'code'    => ['type' => 'string', 'examples' => ['validation_error']],
                            'message' => ['type' => 'string'],
                            'details' => ['type' => ['object', 'array', 'null']],
                        ],
                        'required' => ['code', 'message'],
                    ],
                ],
                'required' => ['error'],
            ],
            'CursorMeta' => [
                'type'       => 'object',
                'properties' => [
                    'next_cursor' => ['type' => ['string', 'null']],
                    'limit'       => ['type' => 'integer'],
                ],
                'required' => ['next_cursor', 'limit'],
            ],
        ], ApiSchemas::all());
    }

    private function webhooks(): array
    {
        $webhooks = [];

        foreach (WebhookEvents::all() as $event) {
            $description = $event->description . ($event->scope ? "\n\nDatos sensibles con el scope `{$event->scope}`." : '');

            $webhooks[$event->key] = [
                'post' => [
                    'operationId' => 'webhook' . Str::studly(str_replace('.', '_', $event->key)),
                    'summary'     => $event->key,
                    'description' => $description,
                    'tags'        => ['Webhooks'],
                    'parameters'  => [
                        ['name' => 'webhook-id', 'in' => 'header', 'required' => true, 'description' => 'Id único del mensaje (idéntico en reintentos: úsalo para deduplicar).', 'schema' => ['type' => 'string']],
                        ['name' => 'webhook-timestamp', 'in' => 'header', 'required' => true, 'description' => 'Segundos Unix del envío.', 'schema' => ['type' => 'string']],
                        ['name' => 'webhook-signature', 'in' => 'header', 'required' => true, 'description' => '`v1,<base64(HMAC-SHA256(secreto, "{id}.{timestamp}.{body}"))>`; varias separadas por espacio durante una rotación.', 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'type'      => ['type' => 'string', 'const' => $event->key],
                                    'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                                    'data'      => ['type' => 'object'],
                                ],
                                'required' => ['type', 'timestamp', 'data'],
                            ],
                            'example' => ['type' => $event->key, 'timestamp' => '2026-01-01T12:00:00-03:00', 'data' => (object) $event->example],
                        ]],
                    ],
                    'responses' => ['2XX' => ['description' => 'Recibido. Cualquier otra respuesta se reintenta (1 min, 5 min, 30 min, 2 h, 6 h, 12 h).']],
                    'x-version' => $event->version,
                    'x-scope'   => $event->scope,
                ],
            ];

            if ($event->scope === null) {
                unset($webhooks[$event->key]['post']['x-scope']);
            }
        }

        return $webhooks;
    }
}
