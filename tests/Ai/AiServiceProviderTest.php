<?php

use Innertia\Ai\AiManager;
use Innertia\Ai\Mcp\EnsureMcpKey;

it('binds AiManager as a singleton', function () {
    expect(app(AiManager::class))->toBe(app(AiManager::class));
});

it('registers the ensure.mcp.key middleware alias when the feature is active', function () {
    expect(app('router')->getMiddleware()['ensure.mcp.key'] ?? null)
        ->toBe(EnsureMcpKey::class);
});
