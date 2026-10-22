<?php

namespace Innertia\Ai\Console;

use Illuminate\Console\Command;
use Innertia\Ai\AiFeature;
use Innertia\Ai\Database\Seeders\AiDefaultsSeeder;

/**
 * Corre el AiDefaultsSeeder (idempotente). Requiere que las tablas del módulo ya
 * existan (correr `php artisan migrate` después de `innertia:ai:install`).
 */
class AiSeedCommand extends Command
{
    protected $signature = 'innertia:ai:seed';

    protected $description = 'Seed default AI providers, models, assignments, RAG collection and MCP server.';

    public function handle(): int
    {
        if (! AiFeature::isActive()) {
            $this->error('Refusing to run: config(innertia.ai.enabled) is not true.');

            return self::FAILURE;
        }

        (new AiDefaultsSeeder)->setContainer($this->laravel)->run();

        $this->info('AI defaults seeded (minimax provider + models + assignments + default collection + question-bank MCP).');

        return self::SUCCESS;
    }
}
