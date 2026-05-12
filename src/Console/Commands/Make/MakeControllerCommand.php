<?php

namespace Innertia\Console\Commands\Make;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeControllerCommand extends Command
{
    protected $signature = 'innertia:make:controller
        {name?      : Controller name (e.g. ProductsController or Products)}
        {--app=     : App name (e.g. BackOffice)}
        {--domain=  : Domain name (e.g. Products)}
        {--model=   : Model class (e.g. Product)}
        {--actions= : Comma-separated actions to include (index,show,store,update,destroy). Default: all}';

    protected $description = 'Create a REST controller wired to DataTable in app/Apps/{App}/{Domain}/Controllers/';

    public function handle(): int
    {
        $name   = $this->argument('name')   ?? $this->ask('Controller name? (e.g. Products or ProductsController)');
        $app    = $this->option('app')      ?? $this->ask('App? (e.g. BackOffice)', 'BackOffice');
        $domain = $this->option('domain')   ?? $this->ask('Domain?', $this->guessDomain($name));
        $model  = $this->option('model')    ?? $this->ask('Model class?', $this->guessModel($name));

        $name      = Str::studly(Str::beforeLast(Str::studly($name), 'Controller')) . 'Controller';
        $app       = Str::studly($app);
        $domain    = Str::studly($domain);
        $model     = Str::studly($model);

        $actionsRaw = $this->option('actions');
        $actions    = $actionsRaw
            ? array_map('trim', explode(',', $actionsRaw))
            : ['index', 'show', 'store', 'update', 'destroy'];

        $path = app_path("Apps/{$app}/{$domain}/Controllers/{$name}.php");

        if (file_exists($path) && ! $this->confirm("Controller {$name} already exists. Overwrite?", false)) {
            return self::SUCCESS;
        }

        $this->writeFile($path, $this->stub($name, $app, $domain, $model, $actions));
        $this->components->info("Controller created: <fg=cyan>app/Apps/{$app}/{$domain}/Controllers/{$name}.php</>");

        $routePrefix = Str::kebab(Str::plural($model));
        $this->newLine();
        $this->components->info('Add to <fg=cyan>routes/api.php</>:');
        $this->line("  Route::apiResource('{$routePrefix}', \\App\\Apps\\{$app}\\{$domain}\\Controllers\\{$name}::class);");

        return self::SUCCESS;
    }

    private function stub(string $name, string $app, string $domain, string $model, array $actions): string
    {
        $modelVar    = Str::camel($model);
        $tableKey    = Str::kebab(Str::plural($model));
        $useCasesUse = $this->buildUseCaseImports($domain, $model, $actions);
        $methods     = $this->buildMethods($model, $modelVar, $tableKey, $domain, $actions);

        return <<<PHP
<?php

namespace App\Apps\\{$app}\\{$domain}\Controllers;

use App\Domains\\{$domain}\Models\\{$model};
{$useCasesUse}
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Innertia\Facades\DataTable;

class {$name}
{
{$methods}
}
PHP;
    }

    private function buildUseCaseImports(string $domain, string $model, array $actions): string
    {
        $map = [
            'store'   => "Create{$model}",
            'update'  => "Update{$model}",
            'destroy' => "Delete{$model}",
        ];

        return collect($actions)
            ->filter(fn ($a) => isset($map[$a]))
            ->map(fn ($a) => "use App\\Domains\\{$domain}\\UseCases\\{$map[$a]};")
            ->implode("\n");
    }

    private function buildMethods(string $model, string $modelVar, string $tableKey, string $domain, array $actions): string
    {
        $methods = [];

        if (in_array('index', $actions)) {
            $methods[] = <<<PHP
    public function index(Request \$request): JsonResponse
    {
        return response()->json(
            DataTable::create('{$tableKey}')
                ->columns(['id', 'name', 'created_at'])
                ->render({$model}::class, \$request)
        );
    }
PHP;
        }

        if (in_array('show', $actions)) {
            $methods[] = <<<PHP
    public function show(string \$id): JsonResponse
    {
        \${$modelVar} = {$model}::findOrFail(\$id);

        return response()->json(\${$modelVar});
    }
PHP;
        }

        if (in_array('store', $actions)) {
            $methods[] = <<<PHP
    public function store(Request \$request): JsonResponse
    {
        \$data = \$request->validate([
            'name' => 'required|string|max:255',
            // TODO: add validation rules
        ]);

        \${$modelVar} = (new Create{$model}(/* TODO: pass \$data fields */))->execute();

        return response()->json(\${$modelVar}, 201);
    }
PHP;
        }

        if (in_array('update', $actions)) {
            $methods[] = <<<PHP
    public function update(Request \$request, string \$id): JsonResponse
    {
        \$data = \$request->validate([
            'name' => 'sometimes|string|max:255',
            // TODO: add validation rules
        ]);

        \${$modelVar} = (new Update{$model}(\$id /* TODO: pass \$data fields */))->execute();

        return response()->json(\${$modelVar});
    }
PHP;
        }

        if (in_array('destroy', $actions)) {
            $methods[] = <<<PHP
    public function destroy(string \$id): JsonResponse
    {
        (new Delete{$model}(\$id))->execute();

        return response()->json(null, 204);
    }
PHP;
        }

        return implode("\n\n", $methods);
    }

    private function guessDomain(string $name): string
    {
        return Str::studly(Str::plural(Str::beforeLast($name, 'Controller')));
    }

    private function guessModel(string $name): string
    {
        return Str::studly(Str::singular(Str::beforeLast($name, 'Controller')));
    }

    private function writeFile(string $path, string $content): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $content);
    }
}
