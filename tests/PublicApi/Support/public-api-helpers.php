<?php

use Innertia\Webhooks\Security\HostResolver;

if (! class_exists('FakeHostResolver')) {
    /** DNS double: host → IPs (default: a public address). */
    class FakeHostResolver implements HostResolver
    {
        public function __construct(public array $map = []) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? ['93.184.216.34'];
        }
    }
}

if (! function_exists('fakeDns')) {
    function fakeDns(array $map = []): FakeHostResolver
    {
        $resolver = new FakeHostResolver($map);
        app()->instance(HostResolver::class, $resolver);

        return $resolver;
    }
}

if (! function_exists('publicApiReset')) {
    function publicApiReset(): void
    {
        \Innertia\Webhooks\WebhookEvents::flush();
        \Innertia\ApiKeys\ApiScopes::flush();
        \Innertia\PublicApi\Docs\ApiSchemas::flush();
        \Innertia\PublicApi\Support\IntegrationsSchema::flush();
        \Innertia\Notifications\NoticeTypes::flush();
        \Illuminate\Support\Facades\RateLimiter::clear('x');
        \Illuminate\Support\Facades\Cache::flush();
        fakeDns();
    }
}

if (! function_exists('bootPublicApiOpenMode')) {
    /** Open mode + organizations (tenant 'rancagua' active) + saas webhooks/api_keys tables + public API migration. */
    function bootPublicApiOpenMode(\Illuminate\Foundation\Application $app): void
    {
        bootOrgOpenMode($app);
        $base = __DIR__ . '/../../../database/migrations';
        (require "{$base}/saas/2025_01_01_000005_create_webhooks_tables.php")->up();
        (require "{$base}/saas/2025_01_01_000009_create_api_keys_table.php")->up();
        (require "{$base}/integrations/2026_11_19_000002_create_public_api_tables.php")->up();
        \Illuminate\Support\Facades\Schema::create('activity_logs', function ($t) {
            $t->bigIncrements('id');
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->string('entity_id')->nullable();
            $t->string('user_id')->nullable();
            $t->string('trace_id')->nullable();
            $t->json('metadata')->nullable();
            $t->text('description')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        publicApiReset();
    }
}

if (! enum_exists('TestCallEventKey')) {
    enum TestCallEventKey: string implements \Innertia\Platform\Events\DomainEventKey
    {
        case Ended = 'call.ended';
        case Ringing = 'call.ringing';
        case PersonCreated = 'person.created';

        public function key(): string
        {
            return $this->value;
        }
    }
}

if (! class_exists('TestCallEvent')) {
    /** DomainEvent with a v1 webhook view: RUT only with people:pii; sede for subscription filtering. */
    class TestCallEvent extends \Innertia\Platform\Events\DomainEvent
    {
        public function __construct(
            public readonly string $callId,
            public readonly ?string $siteId = null,
            public readonly TestCallEventKey $eventKey = TestCallEventKey::Ended,
        ) {}

        public function key(): \Innertia\Platform\Events\DomainEventKey
        {
            return $this->eventKey;
        }

        public function channels(): array
        {
            return ['webhook'];
        }

        public function webhookPayload(array $scopes = []): ?array
        {
            return [
                'id'   => $this->callId,
                'rut'  => in_array('people:pii', $scopes, true) ? '12.345.678-9' : '12.***.***-9',
                'site' => $this->siteId,
            ];
        }

        public function webhookOrganizationId(): ?string
        {
            return $this->siteId;
        }
    }
}
