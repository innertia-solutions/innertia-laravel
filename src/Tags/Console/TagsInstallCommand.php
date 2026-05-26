<?php

namespace Innertia\Tags\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Innertia\Tags\TagsFeature;

/**
 * Crea la migration que introduce las tablas `tags` y `taggables`.
 *
 * Guard: refuses to run unless TagsFeature::isActive().
 * Idempotent: detecta migration previa (sin --force).
 *
 * Schema:
 *   tags      (id uuid, tenant_id nullable, name, slug, color, created_by, timestamps)
 *   taggables (tag_id, taggable_type, taggable_id, tagged_by, tagged_at)
 */
class TagsInstallCommand extends Command
{
    protected $signature = 'innertia:tags:install
        {--path= : Override migrations directory (default: database/migrations)}
        {--force : Generate a new migration even if a previous one exists.}';

    protected $description = 'Generate migrations for the Tags feature (tags + taggables tables).';

    public function handle(): int
    {
        if (! TagsFeature::isActive()) {
            $this->error('Refusing to run: config(innertia.tags.enabled) is not true.');
            return self::FAILURE;
        }

        $dir = $this->option('path') ?: database_path('migrations');
        File::ensureDirectoryExists($dir);

        $existing = glob($dir . '/*_create_tags_tables*.php') ?: [];
        if (count($existing) > 0 && ! $this->option('force')) {
            $this->info('Tags migration already exists at ' . $existing[0] . ' — nothing to do.');
            $this->line('Use --force to regenerate.');
            return self::SUCCESS;
        }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_create_tags_tables.php";
        $path      = $dir . DIRECTORY_SEPARATOR . $filename;

        File::put($path, $this->stub());

        $this->info('Created migration: ' . $path);
        $this->line('Run `php artisan migrate` to apply it.');
        $this->line('Then register routes with: \Innertia\Tags\Routes::register();');

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
 * innertia-laravel — Tags feature install migration.
 *
 * `tags.tenant_id` es nullable:
 *   - NULL: modo app/api (sin multitenancy)
 *   - valor: modo saas, scopeado por HasTenant trait
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('slug', 80);
            $table->string('color', 7)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug'], 'tags_tenant_slug_unique');
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->uuid('tag_id');
            $table->string('taggable_type');
            $table->uuid('taggable_id');
            $table->uuid('tagged_by')->nullable();
            $table->timestamp('tagged_at')->useCurrent();

            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id'], 'taggables_morph_idx');
            $table->index('tag_id', 'taggables_tag_idx');

            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
PHP;
    }
}
