<?php

namespace Innertia\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static string|null configureFor(string $use)
 * @method static \Innertia\Ai\Embeddings\EmbeddingProvider embedder(?string $collectionKey = null)
 * @method static \Innertia\Ai\UsageLogger usage()
 *
 * @see \Innertia\Ai\AiManager
 */
class Ai extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Innertia\Ai\AiManager::class;
    }
}
