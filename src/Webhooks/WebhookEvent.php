<?php

namespace Innertia\Webhooks;

/** Catalog entry of a webhook event (see WebhookEvents::register()). */
final class WebhookEvent
{
    public function __construct(
        public readonly string $key,
        public readonly string $description,
        public readonly string $version = 'v1',
        public readonly array $example = [],
        public readonly ?string $scope = null,
    ) {}

    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'description' => $this->description,
            'version'     => $this->version,
            'scope'       => $this->scope,
            'example'     => $this->example,
        ];
    }
}
