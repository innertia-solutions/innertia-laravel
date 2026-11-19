<?php

use Illuminate\Support\Facades\Route;
use Innertia\ApiKeys\ApiScopes;
use Innertia\PublicApi\Docs\ApiOperation;
use Innertia\PublicApi\Docs\ApiSchemas;
use Innertia\PublicApi\Docs\OpenApiGenerator;
use Innertia\PublicApi\Routes;
use Innertia\Webhooks\WebhookEvents;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\CompliantValidator;

class OpenApiTestPeopleController
{
    #[ApiOperation(summary: 'Detalle de persona', scope: 'people:read', tags: ['Personas'], response: 'Person')]
    public function show(string $id) { return ['data' => ['id' => $id]]; }
}

beforeEach(function () {
    publicApiReset();
    ApiScopes::register('calls:read', 'Consultar llamadas');
    ApiScopes::register('calls:write', 'Crear llamadas');
    ApiScopes::register('people:read', 'Consultar personas');
    ApiSchemas::register('Call', ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']]], 'required' => ['id']]);
    ApiSchemas::register('Person', ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'rut' => ['type' => ['string', 'null']]]]);
    WebhookEvents::register('call.ended', 'Una llamada terminó', 'v1', ['id' => 'c_1', 'duration' => 42]);
    WebhookEvents::register('call.recording_ready', 'Grabación disponible', 'v1', ['id' => 'c_1', 'url' => 'https://x'], scope: 'recordings:read');

    Route::prefix('api')->group(function () {
        Routes::docs();
        Routes::developers();
        Route::prefix('v1')->middleware('api.key')->group(function () {
            Route::get('calls', fn () => [])->name('v1.calls.index')->apiDoc([
                'summary' => 'Lista llamadas', 'scope' => 'calls:read', 'tags' => ['Llamadas'], 'paginated' => true,
                'query' => ['direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound'], 'description' => 'Dirección']],
                'response' => 'Call',
            ]);
            Route::post('calls', fn () => [])->apiDoc([
                'summary' => 'Click-to-call', 'scope' => 'calls:write', 'tags' => ['Llamadas'],
                'body' => ['type' => 'object', 'properties' => ['extension' => ['type' => 'string'], 'number' => ['type' => 'string']], 'required' => ['extension']],
                'response' => 'Call', 'errors' => [422 => 'Anexo sin agente (`extension_unavailable`).'],
            ]);
            Route::get('people/{id}', [OpenApiTestPeopleController::class, 'show']);
            Route::get('undocumented', fn () => []);
        });
    });
});

function validateOpenApi(array $document): array
{
    // Official OAS 3.1 schema (2022-10-07). Its Schema Objects use `$dynamicRef: #meta`, which (without a
    // dialect override) resolves to `$defs/schema` (`$dynamicAnchor: meta`, any object/boolean). opis resolves
    // the dynamic ref to the document root instead, so it is rewritten to the equivalent static `$ref`.
    $schema = str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', file_get_contents(__DIR__ . '/fixtures/openapi-3.1-schema.json'));
    $validator = new CompliantValidator; // spec-compliant: no defaults injection, strict keywords
    $validator->resolver()->registerRaw($schema, 'https://spec.openapis.org/oas/3.1/schema/2022-10-07');
    $result = $validator->validate(json_decode(json_encode($document)), 'https://spec.openapis.org/oas/3.1/schema/2022-10-07');

    return $result->isValid() ? [] : (new ErrorFormatter)->format($result->error());
}

it('generates a valid OpenAPI 3.1 document (validated against the official schema)', function () {
    $doc = app(OpenApiGenerator::class)->generate();

    expect(validateOpenApi($doc))->toBe([])
        ->and($doc['openapi'])->toBe('3.1.0')
        ->and(array_keys($doc['paths']))->toBe(['/api/v1/calls', '/api/v1/people/{id}'])
        ->and($doc['paths']['/api/v1/calls']['get']['x-scope'])->toBe('calls:read')
        ->and($doc['paths']['/api/v1/calls']['get']['operationId'])->toBe('v1CallsIndex')
        ->and(collect($doc['paths']['/api/v1/calls']['get']['parameters'])->pluck('name')->all())->toBe(['direction', 'cursor', 'limit', 'updated_since'])
        ->and(collect($doc['paths']['/api/v1/calls']['post']['parameters'])->pluck('name')->all())->toBe(['Idempotency-Key'])
        ->and($doc['paths']['/api/v1/calls']['post']['responses'])->toHaveKeys(['201', '401', '403', '409', '422', '429'])
        ->and($doc['paths']['/api/v1/people/{id}']['get']['x-scope'])->toBe('people:read')   // from the attribute
        ->and($doc['webhooks'])->toHaveKeys(['call.ended', 'call.recording_ready'])
        ->and($doc['webhooks']['call.recording_ready']['post']['x-scope'])->toBe('recordings:read')
        ->and($doc['components']['schemas'])->toHaveKeys(['Error', 'CursorMeta', 'Call', 'Person']);
});

it('an empty product (no routes, no events) still produces a valid document', function () {
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    WebhookEvents::flush();

    expect(validateOpenApi(app(OpenApiGenerator::class)->generate()))->toBe([]);
});

it('the attribute scope is enforced by the api.key middleware too', function () {
    $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/people/1'));

    expect(\Innertia\PublicApi\Docs\RouteDescriptor::scopeFor($route))->toBe('people:read');
});

it('serves openapi.json publicly and the /developers page with pinned Scalar', function () {
    $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->assertJsonPath('paths./api/v1/calls.get.summary', 'Lista llamadas');

    $this->get('/api/developers')->assertOk()
        ->assertSee('https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.76.4/dist/browser/standalone.js', false)
        ->assertSee('api/v1/openapi.json', false);
});

it('the validator does reject a broken document (sanity check of the schema setup)', function () {
    $doc = app(OpenApiGenerator::class)->generate();
    unset($doc['info']);
    $doc['paths']['/api/v1/calls']['get']['responses'] = 'nope';

    expect(validateOpenApi($doc))->not->toBe([]);
});
