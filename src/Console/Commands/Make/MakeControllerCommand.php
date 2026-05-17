<?php

namespace Innertia\Console\Commands\Make;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeControllerCommand extends Command
{
    protected $signature = 'innertia:make:controller
        {name?      : Controller name (e.g. ChatsController or Chats)}
        {--group=   : Endpoint group / subdirectory (e.g. Chat, Documents). API mode only.}
        {--app=     : App name (e.g. BackOffice). App/SaaS mode only.}
        {--domain=  : Domain name (e.g. Orders)}
        {--model=   : Model class (e.g. Order)}
        {--actions= : Comma-separated actions (index,show,store,update,destroy). Default: all}';

    protected $description = 'Create a REST controller. Path depends on mode: api→app/Api/{Group}/, app→app/Apps/{App}/{Domain}/Controllers/';

    public function handle(): int
    {
        return config('innertia.mode') === 'api'
            ? $this->handleApiMode()
            : $this->handleAppMode();
    }

    // ── API mode ──────────────────────────────────────────────────────────────

    private function handleApiMode(): int
    {
        $name  = $this->argument('name') ?? $this->ask('Controller name? (e.g. Chats or ChatsController)');
        $group = $this->option('group')  ?? $this->ask('Endpoint group?', $this->guessDomain($name));
        $model = $this->option('model')  ?? $this->ask('Model class?', $this->guessModel($name));

        $name  = Str::studly(Str::beforeLast(Str::studly($name), 'Controller')) . 'Controller';
        $group = Str::studly($group);
        $model = Str::studly($model);

        $actions = $this->parseActions();
        $path    = app_path("Api/{$group}/{$name}.php");

        if (file_exists($path) && ! $this->confirm("Controller {$name} already exists. Overwrite?", false)) {
            return self::SUCCESS;
        }

        $this->writeFile($path, $this->stubApiMode($name, $group, $model, $actions));
        $this->components->info("Controller created: <fg=cyan>app/Api/{$group}/{$name}.php</>");

        $routePrefix = Str::kebab(Str::plural($model));
        $this->newLine();
        $this->components->info('Add to <fg=cyan>routes/api.private.php</>:');
        $this->line("  Route::apiResource('{$routePrefix}', \\App\\Api\\{$group}\\{$name}::class);");

        return self::SUCCESS;
    }

    private function stubApiMode(string $name, string $group, string $model, array $actions): string
    {
        $modelVar    = Str::camel($model);
        $domainGuess = Str::studly(Str::plural($model));
        $useCasesUse = $this->buildUseCaseImports($domainGuess, $model, $actions);
        $methods     = $this->buildApiMethods($model, $modelVar, $domainGuess, $actions);
        $tableImport = in_array('index', $actions) ? "\nuse Innertia\Facades\DataTable;" : '';

        return <<<PHP
<?php

namespace App\Api\\{$group};

use App\Domains\\{$domainGuess}\Models\\{$model};
{$useCasesUse}
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;{$tableImport}

class {$name}
{
{$methods}
}
PHP;
    }

    private function buildApiMethods(string $model, string $modelVar, string $domain, array $actions): string
    {
        $methods = [];

        if (in_array('index', $actions)) {
            $tableKey  = Str::kebab(Str::plural($model));
            $methods[] = <<<PHP
    public function index(Request \$request): JsonResponse
    {
        \$client = \$request->attributes->get('client');

        return response()->json(
            DataTable::create('{$tableKey}')
                ->columns(['id', 'created_at'])
                ->filter(fn (\$q) => \$q->where('client_id', \$client->id))
                ->render({$model}::class, \$request)
        );
    }
PHP;
        }

        if (in_array('show', $actions)) {
            $methods[] = <<<PHP
    public function show(Request \$request, string \$id): JsonResponse
    {
        \$client    = \$request->attributes->get('client');
        \${$modelVar} = {$model}::where('client_id', \$client->id)->findOrFail(\$id);

        return response()->json(\${$modelVar});
    }
PHP;
        }

        if (in_array('store', $actions)) {
            $methods[] = <<<PHP
    public function store(Request \$request): JsonResponse
    {
        \$data   = \$request->validate([
            // TODO: add validation rules
        ]);
        \$client = \$request->attributes->get('client');

        \${$modelVar} = (new Create{$model}(
            client: \$client,
            // TODO: pass \$data fields
        ))->execute();

        return response()->json(\${$modelVar}, 201);
    }
PHP;
        }

        if (in_array('update', $actions)) {
            $methods[] = <<<PHP
    public function update(Request \$request, string \$id): JsonResponse
    {
        \$data   = \$request->validate([
            // TODO: add validation rules
        ]);
        \$client = \$request->attributes->get('client');

        \${$modelVar} = (new Update{$model}(
            client: \$client,
            id:     \$id,
            // TODO: pass \$data fields
        ))->execute();

        return response()->json(\${$modelVar});
    }
PHP;
        }

        if (in_array('destroy', $actions)) {
            $methods[] = <<<PHP
    public function destroy(Request \$request, string \$id): JsonResponse
    {
        \$client = \$request->attributes->get('client');

        (new Delete{$model}(client: \$client, id: \$id))->execute();

        return response()->json(null, 204);
    }
PHP;
        }

        return implode("\n\n", $methods);
    }

    // ── App / SaaS mode ───────────────────────────────────────────────────────

    private function handleAppMode(): int
    {
        $name   = $this->argument('name')   ?? $this->ask('Controller name? (e.g. Products or ProductsController)');
        $app    = $this->option('app')      ?? $this->ask('App? (e.g. BackOffice)', 'BackOffice');
        $domain = $this->option('domain')   ?? $this->ask('Domain?', $this->guessDomain($name));
        $model  = $this->option('model')    ?? $this->ask('Model class?', $this->guessModel($name));

        $name   = Str::studly(Str::beforeLast(Str::studly($name), 'Controller')) . 'Controller';
        $app    = Str::studly($app);
        $domain = Str::studly($domain);
        $model  = Str::studly($model);

        $actions = $this->parseActions();
        $path    = app_path("Apps/{$app}/{$domain}/Controllers/{$name}.php");

        if (file_exists($path) && ! $this->confirm("Controller {$name} already exists. Overwrite?", false)) {
            return self::SUCCESS;
        }

        $this->writeFile($path, $this->stubAppMode($name, $app, $domain, $model, $actions));
        $this->components->info("Controller created: <fg=cyan>app/Apps/{$app}/{$domain}/Controllers/{$name}.php</>");

        $routePrefix = Str::kebab(Str::plural($model));
        $this->newLine();
        $this->components->info('Add to <fg=cyan>routes/api.private.php</>:');
        $this->line("  Route::apiResource('{$routePrefix}', \\App\\Apps\\{$app}\\{$domain}\\Controllers\\{$name}::class);");

        return self::SUCCESS;
    }

    private function stubAppMode(string $name, string $app, string $domain, string $model, array $actions): string
    {
        $modelVar    = Str::camel($model);
        $tableKey    = Str::kebab(Str::plural($model));
        $useCasesUse = $this->buildUseCaseImports($domain, $model, $actions);
        $methods     = $this->buildAppMethods($model, $modelVar, $tableKey, $domain, $actions);

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

    private function buildAppMethods(string $model, string $modelVar, string $tableKey, string $domain, array $actions): string
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

    // ── Shared ────────────────────────────────────────────────────────────────

    private function parseActions(): array
    {
        $raw = $this->option('actions');
        return $raw
            ? array_map('trim', explode(',', $raw))
            : ['index', 'show', 'store', 'update', 'destroy'];
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
