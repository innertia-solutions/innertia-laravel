<?php

namespace Innertia\Platform\Teams\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Innertia\Platform\Teams\TeamsFeature;

/**
 * Crea la migration que introduce las tablas `teams` y `team_members`.
 *
 * Guard: refuses to run unless TeamsFeature::isActive().
 * Idempotent: re-running detects existing `*_create_teams_tables_*.php` and skips.
 *
 * Schema:
 *   teams (id uuid, tenant_id, organization_id NULLABLE, name, description, parent_team_id, timestamps)
 *   team_members (team_id, user_id, role_in_team, joined_at)
 *
 * organization_id nullable porque teams pueden ser tenant-level (sin orgs) o
 * org-scoped (con orgs activos). La validación de cuál usar queda en lógica
 * de aplicación según OrganizationsFeature::isActive().
 */
class TeamsInstallCommand extends Command
{
    protected $signature = 'innertia:teams:install
        {--path= : Override migrations directory (default: database/migrations)}
        {--force : Generate a new migration even if a previous one exists.}';

    protected $description = 'Generate migrations for the Teams feature (teams + team_members tables).';

    public function handle(): int
    {
        if (! TeamsFeature::isActive()) {
            if (config('innertia.mode') === 'api') {
                $this->error('Refusing to run: Teams feature is inactive in api mode.');
            } else {
                $this->error('Refusing to run: config(innertia.teams.enabled) is not true.');
            }
            return self::FAILURE;
        }

        $dir = $this->option('path') ?: database_path('migrations');
        File::ensureDirectoryExists($dir);

        $existing = glob($dir . '/*_create_teams_tables*.php') ?: [];
        if (count($existing) > 0 && ! $this->option('force')) {
            $this->info('Teams migration already exists at ' . $existing[0] . ' — nothing to do.');
            $this->line('Use --force to regenerate.');
            return self::SUCCESS;
        }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_create_teams_tables.php";
        $path      = $dir . DIRECTORY_SEPARATOR . $filename;

        File::put($path, $this->stub());

        $this->info('Created migration: ' . $path);
        $this->line('Run `php artisan migrate` to apply it.');

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
 * innertia-laravel — Teams feature install migration.
 *
 * Crea `teams` y `team_members`. `teams.organization_id` es nullable:
 *   - NULL: team es tenant-level (orgs deshabilitadas o team global)
 *   - valor: team pertenece a esa org
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->uuid('parent_team_id')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'organization_id'], 'teams_tenant_org_idx');
        });

        // FK self-referencial fuera del Schema::create — Postgres no ve el PK
        // como unique constraint hasta que la tabla esté creada.
        Schema::table('teams', function (Blueprint $table) {
            $table->foreign('parent_team_id')->references('id')->on('teams')->nullOnDelete();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role_in_team')->default('member'); // 'member' | 'lead'
            $table->timestamp('joined_at')->useCurrent();

            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
PHP;
    }
}
