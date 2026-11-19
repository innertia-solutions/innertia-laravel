<?php

namespace Innertia\PublicApi\Docs;

use Attribute;

/**
 * Describes a public API endpoint (OpenAPI + scope enforced by the `api.key` middleware).
 * Same keys as the route macro `->apiDoc([...])`:
 *
 *   #[ApiOperation(summary: 'Lista llamadas', scope: 'calls:read', tags: ['Llamadas'], paginated: true,
 *       query: ['direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']]], response: 'Call')]
 *   public function index(Request $request) { … }
 *
 * Schemas: a registered component name ('Call'), 'Call[]' for a list, or an inline JSON Schema array.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiOperation
{
    public function __construct(
        public readonly string $summary,
        public readonly ?string $scope = null,
        public readonly ?string $description = null,
        public readonly array $tags = [],
        public readonly array $query = [],
        public readonly array $path = [],
        public readonly string|array|null $body = null,
        public readonly string|array|null $response = null,
        public readonly bool $paginated = false,
        public readonly ?int $status = null,
        public readonly array $errors = [],
        public readonly ?string $operationId = null,
    ) {}

    public function toArray(): array
    {
        return array_filter(get_object_vars($this), fn ($v) => $v !== null && $v !== [] && $v !== false);
    }
}
