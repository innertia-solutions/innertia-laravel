---
name: innertia-extending
description: Use when extending or customizing Innertia package functionality — adding columns to Organizations/Teams, overriding UseCases, modifying default Controllers, container binding for swap, side-effects post-create. Trigger for "agregar campos a", "extender modelo del paquete", "modificar UseCase", "personalizar controller del paquete".
---

# Extender funcionalidades del paquete innertia-laravel

El paquete sigue un patrón consistente para extensibilidad: **template method en UseCases** + **hooks en Controllers** + **swap por config**.

## Niveles de extensibilidad (de menos a más invasivo)

| Nivel | Necesidad | Mecanismo |
|---|---|---|
| 1 | Agregar columnas a tablas del paquete | Migration app + extender modelo + hooks del controller |
| 2 | Cambiar mapping de atributos (rename, transform, defaults) | Extender UseCase + override `attributes()` |
| 3 | Side-effects post-create (eventos, notifs, jobs) | Extender UseCase + override `execute()` |
| 4 | Reemplazar UseCase entero | Subclase + container bind + override método del controller que lo llama |
| 5 | Reemplazar controller entero | Forkear y montar via `Routes::register('prefix', App\Controller::class)` |

## Nivel 1 — Agregar columnas (caso más común)

Ejemplo: `owner_id` en Organizations.

### 1. Migration de la app

```php
Schema::table('organizations', function (Blueprint $t) {
    $t->uuid('owner_id')->nullable();
    $t->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
});
```

### 2. Modelo extendido en la app

```php
namespace App\Domains\Organizations\Models;

class Organization extends \Innertia\Platform\Organizations\Models\Organization {
    protected $fillable = [...parent::$fillable, 'owner_id'];

    public function owner() {
        return $this->belongsTo(\App\Domains\Users\Models\User::class, 'owner_id');
    }
}
```

### 3. Apuntar config al modelo de la app

```php
// config/innertia.php
'organizations' => [
    // ...
    'model' => \App\Domains\Organizations\Models\Organization::class,
],
```

Esto hace que **todos** los UseCases y el controller default usen tu subclase. Sin esto, instanciás el modelo del paquete sin `owner_id` en fillable.

### 4. Controller extendido — hooks

```php
namespace App\Apps\Backoffice\Organizations;

use Illuminate\Http\Request;
use Innertia\Platform\Organizations\Http\Controllers\OrganizationsController as BaseController;

class OrganizationsController extends BaseController {
    protected function extraStoreRules(): array {
        return ['owner_id' => 'required|uuid|exists:users,id'];
    }

    protected function extraUpdateRules(): array {
        return ['owner_id' => 'sometimes|uuid|exists:users,id'];
    }

    protected function extraFields(Request $r, $org = null): array {
        return array_filter(
            ['owner_id' => $r->input('owner_id')],
            fn ($v) => $v !== null,
        );
    }

    protected function indexColumns(): array {
        return [...parent::indexColumns(), 'owner_id'];
    }
}
```

### 5. Mount con tu controller

```php
// routes/api.private.php
\Innertia\Platform\Organizations\Routes::register(
    'organizations',
    \App\Apps\Backoffice\Organizations\OrganizationsController::class,
);
```

## Hooks disponibles por Controller

### `OrganizationsController` / `TeamsController`

| Hook | Cuándo se usa | Default |
|---|---|---|
| `extraStoreRules(): array` | reglas validation extra para POST | `[]` |
| `extraUpdateRules(): array` | reglas validation extra para PUT | `[]` |
| `extraFields(Request $r, ?Model $entity = null): array` | mapping request → atributos del modelo. `$entity` es null en store, populated en update | `[]` |
| `indexColumns(): array` (solo Orgs) | columnas a incluir en el DataTable del index | `['id', 'name', 'key', 'active', 'created_at']` |
| `showRelations(): array` (solo Teams) | relaciones a eager-load en GET /{id} | `['members:id,name,email', 'parent:id,name', 'children:id,name,parent_team_id']` |
| `model(): string` | clase del modelo. Por default lee de config | `config('innertia.{feature}.model')` |

## Nivel 2 — Cambiar mapping en el UseCase

Si la transformación es demasiado compleja para `extraFields` (p.ej. parsear un slug desde otro campo, aplicar defaults, denormalizar):

```php
namespace App\Domains\Organizations\UseCases;

use Innertia\Platform\Organizations\UseCases\CreateOrganization as Base;

class CreateOrganization extends Base {
    protected function attributes(): array {
        return array_merge(parent::attributes(), [
            'slug_canonical' => \Illuminate\Support\Str::slug($this->name),
            'metadata'       => ['created_via' => 'api'],
        ]);
    }
}
```

Para que el controller use TU UseCase:

```php
class OrganizationsController extends BaseController {
    public function store(Request $request) {
        // override store completo o, mejor, refactorear el método que instancia el UseCase
        // (esta es la limitación actual: los UseCases se instancian con `new`, no via container)
        // ...
    }
}
```

**Alternativa más limpia**: extender el UseCase y override `store()` del controller.

## Nivel 3 — Side-effects en `execute()`

Para emitir eventos, despachar jobs, notifs:

```php
class CreateOrganization extends Base {
    public function execute(): OrganizationContract {
        $org = parent::execute();

        event(new \App\Events\OrganizationProvisioned($org));
        \App\Jobs\BootstrapOrgDirectories::dispatch($org)->onQueue('infra');

        return $org;
    }
}
```

## Nivel 4 — Container binding (caso avanzado)

Útil cuando querés que el paquete instancie tu versión sin tocar el controller. Limitación: el controller default usa `new ClassName(...)`, no `app()->make(...)`. Para que el binding tenga efecto, hay que extender también el controller y cambiar la instanciación a `app()->makeWith()`. Si llegaste a esto, probablemente nivel 5 sea más simple.

## Nivel 5 — Reemplazo total del controller

```php
\Innertia\Platform\Organizations\Routes::register(
    prefix: 'organizations',
    controller: \App\Http\OrganizationsController::class,
);
```

Tu controller puede ser totalmente custom — no necesita extender el del paquete. Solo respeta la interfaz REST si querés que tus rutas y el shape de respuesta sean consistentes con apps hermanas.

## Patrón equivalente en Teams

Los hooks son los mismos (`extraStoreRules`, `extraUpdateRules`, `extraFields`), más `showRelations()` propio.

## Anti-patrones

- ❌ **Editar archivos en `vendor/`**: cualquier `composer update` te lo borra. Si el bug es del paquete, abrir issue/PR y patchear con `composer.json` `extra.merge-plugin` o `replace`.
- ❌ **No declarar el modelo extendido en config**: los UseCases default seguirán usando el modelo del paquete y tus columnas extra no se persistirán.
- ❌ **Hacer override de UseCases sin extender el controller**: el controller default instancia con `new`, no ve el binding del container.
- ❌ **Lógica de validación en UseCases**: las reglas de validación van en el controller (hooks `extraStoreRules`). Los UseCases asumen input ya validado.

## Skills relacionados

- `innertia-organizations` — feature, instalación
- `innertia-teams` — feature, instalación
- `innertia-usecases` — patrón UseCase + queue
