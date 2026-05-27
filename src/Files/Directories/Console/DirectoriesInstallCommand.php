<?php

namespace Innertia\Files\Directories\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Innertia\Files\Directories\DirectoriesFeature;

class DirectoriesInstallCommand extends Command
{
    protected $signature = 'innertia:directories:install
        {--path= : Override migrations directory (default: database/migrations)}
        {--force : Generate a new migration even if a previous one exists.}';

    protected $description = 'Generate migration for the Directories feature.';

    public function handle(): int
    {
        if (! DirectoriesFeature::isActive()) {
            $this->error('Refusing to run: config(innertia.directories.enabled) is not true.');
            return self::FAILURE;
        }

        $dir = $this->option('path') ?: database_path('migrations');
        File::ensureDirectoryExists($dir);

        $existing = glob($dir . '/*_create_directories_table*.php') ?: [];
        if (count($existing) > 0 && ! $this->option('force')) {
            $this->info('Directories migration already exists at ' . $existing[0] . ' — nothing to do.');
            $this->line('Use --force to regenerate.');
            return self::SUCCESS;
        }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_create_directories_table.php";
        $path      = $dir . DIRECTORY_SEPARATOR . $filename;

        File::put($path, $this->stub());

        $this->info('Created migration: ' . $path);
        $this->line('Run `php artisan migrate` to apply.');
        $this->line('Register routes with: \Innertia\Files\Directories\Routes::register();');

        return self::SUCCESS;
    }

    private function stub(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->uuid('parent_id')->nullable()->index();
            $table->string('path', 4096);
            $table->unsignedSmallInteger('depth')->default(0);

            $table->string('name');
            $table->string('name_normalized', 255);

            $table->string('owner_type')->nullable();
            $table->uuid('owner_id')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('trash_group_id')->nullable()->index();

            $table->index(['tenant_id', 'owner_type', 'owner_id'], 'directories_owner_idx');
            $table->index(['tenant_id', 'parent_id'], 'directories_parent_idx');
            $table->index('path');
        });

        // Partial unique index (Postgres). For SQLite tests we fall back to a regular
        // unique index — SQLite doesn't enforce composite-with-NULL as duplicate, so
        // the application-layer guard (CreateDirectory use case) is the real safeguard.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX directories_name_unique
                ON directories (tenant_id, owner_type, owner_id, parent_id, name_normalized)
                WHERE deleted_at IS NULL;
            SQL);
        } else {
            Schema::table('directories', function (Blueprint $table) {
                $table->unique(
                    ['tenant_id', 'owner_type', 'owner_id', 'parent_id', 'name_normalized'],
                    'directories_name_unique'
                );
            });
        }

        // Wire files table integration — adds directory_id if files exists and column missing
        if (Schema::hasTable('files') && ! Schema::hasColumn('files', 'directory_id')) {
            Schema::table('files', function (Blueprint $table) {
                $table->uuid('directory_id')->nullable()->index()->after('owner_id');
            });
        }
    }

    public function down(): void
    {
        // Remove directory_id from files if we added it
        if (Schema::hasColumn('files', 'directory_id')) {
            Schema::table('files', function (Blueprint $table) {
                $table->dropIndex(['directory_id']);
                $table->dropColumn('directory_id');
            });
        }

        Schema::dropIfExists('directories');
    }
};
PHP;
    }
}
