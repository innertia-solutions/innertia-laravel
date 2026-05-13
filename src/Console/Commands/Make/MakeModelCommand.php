<?php

namespace Innertia\Console\Commands\Make;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeModelCommand extends Command
{
    protected $signature = 'innertia:make:model
        {name?        : Model class name (e.g. Product)}
        {--domain=    : Domain name (e.g. Products)}
        {--migration  : Also create a migration}
        {--factory    : Also create a factory}';

    protected $description = 'Create a domain model in app/Domains/{Domain}/Models/';

    public function handle(): int
    {
        $name   = $this->argument('name')    ?? $this->ask('Model name? (e.g. Product)');
        $domain = $this->option('domain')    ?? $this->ask('Domain? (e.g. Products)', Str::plural($name));

        $name   = Str::studly($name);
        $domain = Str::studly($domain);
        $table  = Str::snake(Str::plural($name));

        $path = app_path("Domains/{$domain}/Models/{$name}.php");

        if (file_exists($path) && ! $this->confirm("Model {$name} already exists. Overwrite?", false)) {
            return self::SUCCESS;
        }

        $this->writeFile($path, $this->stub($name, $domain));
        $this->components->info("Model created: <fg=cyan>app/Domains/{$domain}/Models/{$name}.php</>");

        if ($this->option('migration') || $this->confirm('Create a migration?', true)) {
            $this->call('make:migration', ['name' => "create_{$table}_table"]);
        }

        if ($this->option('factory') || $this->confirm('Create a factory?', false)) {
            $this->call('make:factory', ['name' => "{$name}Factory", '--model' => "App\\Domains\\{$domain}\\Models\\{$name}"]);
        }

        return self::SUCCESS;
    }

    private function stub(string $name, string $domain): string
    {
        return <<<PHP
<?php

namespace App\Domains\\{$domain}\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Innertia\Platform\Traits\Auditable;
use Innertia\Platform\Traits\HasHistory;
use Innertia\Platform\Traits\HasUuid;

class {$name} extends Model
{
    use Auditable, HasHistory, HasUuid, SoftDeletes;

    protected \$fillable = [
        'name',
    ];

    protected function casts(): array
    {
        return [
            // 'field' => 'boolean',
        ];
    }
}
PHP;
    }

    private function writeFile(string $path, string $content): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $content);
    }
}
