<?php

namespace Innertia\Console\Commands\Make;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeUseCaseCommand extends Command
{
    protected $signature = 'innertia:make:usecase
        {name?      : UseCase name (e.g. CreateProduct)}
        {--domain=  : Domain name (e.g. Products)}
        {--model=   : Model class to return (e.g. Product)}';

    protected $description = 'Create a UseCase in app/Domains/{Domain}/UseCases/';

    public function handle(): int
    {
        $name   = $this->argument('name')  ?? $this->ask('UseCase name? (e.g. CreateProduct)');
        $domain = $this->option('domain')  ?? $this->ask('Domain?', $this->guessDomain($name));
        $model  = $this->option('model')   ?? $this->ask('Return model class? (e.g. Product, or leave blank for mixed)', $this->guessModel($name));

        $name   = Str::studly($name);
        $domain = Str::studly($domain);
        $model  = $model ? Str::studly($model) : null;

        $path = app_path("Domains/{$domain}/UseCases/{$name}.php");

        if (file_exists($path) && ! $this->confirm("UseCase {$name} already exists. Overwrite?", false)) {
            return self::SUCCESS;
        }

        $this->writeFile($path, $this->stub($name, $domain, $model));
        $this->components->info("UseCase created: <fg=cyan>app/Domains/{$domain}/UseCases/{$name}.php</>");
        $this->components->bullet('Run: <fg=cyan>(new ' . $name . '(...))->execute()</>');

        return self::SUCCESS;
    }

    private function stub(string $name, string $domain, ?string $model): string
    {
        $modelImport  = $model ? "\nuse App\\Domains\\{$domain}\\Models\\{$model};" : '';
        $returnType   = $model ?? 'mixed';
        $modelHint    = $model ? "return {$model}::create([]);" : '// TODO: implement';

        return <<<PHP
<?php

namespace App\Domains\\{$domain}\UseCases;
{$modelImport}
use Innertia\Platform\Contracts\UseCase;

class {$name} extends UseCase
{
    public function __construct(
        // TODO: add your parameters
        // public readonly string \$name,
    ) {}

    public function execute(): {$returnType}
    {
        {$modelHint}
    }
}
PHP;
    }

    private function guessDomain(string $name): string
    {
        // CreateProduct → Products
        $base = preg_replace('/^(Create|Update|Delete|Get|List|Send|Sync|Assign|Remove)/', '', $name);
        return Str::studly(Str::plural($base));
    }

    private function guessModel(string $name): string
    {
        return preg_replace('/^(Create|Update|Delete|Get|List|Send|Sync|Assign|Remove)/', '', $name);
    }

    private function writeFile(string $path, string $content): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $content);
    }
}
