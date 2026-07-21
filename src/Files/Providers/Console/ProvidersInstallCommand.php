<?php

namespace Innertia\Files\Providers\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ProvidersInstallCommand extends Command
{
    protected $signature = 'innertia:providers:install
        {--path= : Override migrations directory (default: database/migrations)}
        {--force : Generate a new migration even if a previous one exists.}';

    protected $description = 'Generate migration for the Files provider connections (Google Drive et al).';

    public function handle(): int
    {
        $dir = $this->option('path') ?: database_path('migrations');
        File::ensureDirectoryExists($dir);

        $existing = glob($dir . '/*_create_provider_connections_table*.php') ?: [];
        if (count($existing) > 0 && ! $this->option('force')) {
            $this->info('Provider connections migration already exists at ' . $existing[0] . ' — nothing to do.');
            $this->line('Use --force to regenerate.');
            return self::SUCCESS;
        }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_create_provider_connections_table.php";
        $path      = $dir . DIRECTORY_SEPARATOR . $filename;

        File::put($path, $this->stub());

        $this->info('Created migration: ' . $path);
        $this->line('Run `php artisan migrate` to apply.');

        return self::SUCCESS;
    }

    private function stub(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('provider_connections')) {
            Schema::create('provider_connections', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('provider');
                $table->string('scope'); // user | platform | tenant
                $table->uuid('user_id')->nullable();
                $table->string('tenant_id')->nullable();
                $table->string('email')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('shared_drive_id')->nullable();
                $table->string('app_folder_id')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'scope', 'user_id', 'tenant_id'], 'provider_connections_scope_unique');
            });
        }

        // Wire the files table integration — adds link columns if files exists and columns missing.
        if (Schema::hasTable('files')) {
            foreach (['provider', 'external_id', 'external_url'] as $col) {
                if (! Schema::hasColumn('files', $col)) {
                    Schema::table('files', fn (Blueprint $table) => $table->string($col)->nullable());
                }
            }

            if (! Schema::hasColumn('files', 'external_account_id')) {
                Schema::table('files', fn (Blueprint $table) => $table->uuid('external_account_id')->nullable());
            }

            if (! Schema::hasColumn('files', 'link_mode')) {
                Schema::table('files', fn (Blueprint $table) => $table->string('link_mode')->default('local'));
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('files')) {
            foreach (['provider', 'external_id', 'external_url', 'external_account_id', 'link_mode'] as $col) {
                if (Schema::hasColumn('files', $col)) {
                    Schema::table('files', fn (Blueprint $table) => $table->dropColumn($col));
                }
            }
        }

        Schema::dropIfExists('provider_connections');
    }
};
PHP;
    }
}
