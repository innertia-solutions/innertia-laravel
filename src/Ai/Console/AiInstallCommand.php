<?php

namespace Innertia\Ai\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Innertia\Ai\AiFeature;

/**
 * Crea la migration que introduce las tablas del módulo `Innertia\Ai`.
 *
 * Guard: refuses to run unless AiFeature::isActive().
 * Idempotent: detecta migration previa (sin --force).
 *
 * Schema (10 tablas):
 *   ai_providers, ai_models, ai_assignments, ai_usage_events,
 *   ai_rag_collections, ai_doc_chunks, ai_conversations, ai_messages,
 *   ai_mcp_servers, ai_index_runs
 *
 * La columna `embedding` de `ai_doc_chunks` es driver-aware: tipo nativo
 * `vector` (pgvector) en pgsql, fallback a `text` en sqlite/otros.
 */
class AiInstallCommand extends Command
{
    protected $signature = 'innertia:ai:install
        {--path= : Override migrations directory (default: database/migrations)}
        {--force : Generate a new migration even if a previous one exists.}';

    protected $description = 'Generate migrations for the Ai feature (10 tables, driver-aware pgvector).';

    public function handle(): int
    {
        if (! AiFeature::isActive()) {
            $this->error('Refusing to run: config(innertia.ai.enabled) is not true.');
            return self::FAILURE;
        }

        $dir = $this->option('path') ?: database_path('migrations');
        File::ensureDirectoryExists($dir);

        $existing = glob($dir . '/*_create_ai_tables*.php') ?: [];
        if (count($existing) > 0 && ! $this->option('force')) {
            $this->info('Ai migration already exists at ' . $existing[0] . ' — nothing to do.');
            $this->line('Use --force to regenerate.');
            return self::SUCCESS;
        }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_create_ai_tables.php";
        $path      = $dir . DIRECTORY_SEPARATOR . $filename;

        File::put($path, $this->stub());

        $this->info('Created migration: ' . $path);
        $this->line('Run `php artisan migrate` to apply it.');
        $this->line('Then seed defaults with: php artisan innertia:ai:seed');

        return self::SUCCESS;
    }

    private function stub(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * innertia-laravel — Ai feature install migration.
 *
 * La columna `embedding` de `ai_doc_chunks` es driver-aware:
 *   - pgsql: tipo nativo `vector` (requiere extensión pgvector)
 *   - otros (sqlite): fallback a `text` nullable
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = Schema::getConnection()->getDriverName() === 'pgsql';
        if ($isPg) {
            \Illuminate\Support\Facades\DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('ai_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('type');                 // openai-compatible | openai | minimax | fake
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable();    // encrypted at the model layer
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provider_id')->index();
            $table->string('key');
            $table->string('name');
            $table->json('capabilities')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('use')->unique();        // chat | vision | embedding | assistant
            $table->uuid('provider_id');
            $table->string('model');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provider_id')->nullable();
            $table->string('model')->nullable();
            $table->string('use');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->uuid('user_id')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('ai_rag_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->uuid('embedding_provider_id')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('dimension')->default(1536);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_doc_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->index();
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('token_count')->default(0);
            $table->timestamp('created_at')->nullable();
        });
        // pgvector column is driver-aware: real `vector` on pgsql, text fallback on sqlite
        if ($isPg) {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE ai_doc_chunks ADD COLUMN embedding vector');
        } else {
            Schema::table('ai_doc_chunks', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->index();
            $table->string('role');                 // user | assistant | system | tool
            $table->text('content');
            $table->json('attachments')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('ai_mcp_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->text('api_key')->nullable();    // encrypted at the model layer
            $table->timestamps();
        });

        Schema::create('ai_index_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collection_id')->nullable()->index();
            $table->string('status');               // running | done | failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            $table->text('error')->nullable();
            $table->uuid('triggered_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_index_runs');
        Schema::dropIfExists('ai_mcp_servers');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_doc_chunks');
        Schema::dropIfExists('ai_rag_collections');
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_assignments');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
PHP;
    }
}
