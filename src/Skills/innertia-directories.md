---
name: innertia-directories
description: Tree-organized directories with materialized path, trash with grouped restore, dispatched events. Trigger for "directorios", "carpetas", "tree", "moveTo", "trash", "Directory model", "DirectoryEvent".
---

# Innertia Directories

Sistema de carpetas jerárquicas opt-in, polimórfico, owner-scoped y con materialied path. Permite organizar cualquier entidad (tenant, user, organización) en un árbol libre estilo Drive.

## Cuándo usar

- Usuarios necesitan organizar entidades en carpetas anidadas (Drive-style).
- Jerarquía libre, sin profundidad fija ni tipos de nodo predefinidos.
- Papelera (trash) con restauración agrupada — si movés una carpeta a trash, sus descendientes se restauran en bloque.
- Eventos tipados para auditoría o side-effects (sync, webhooks, notificaciones).

## Cuándo NO usar

- Necesitás categorías tipadas con metadata por tipo → modelo de dominio propio (`ProjectCategory`, `AssetType`).
- Querés solo etiquetas transversales sin jerarquía → Tags feature (`HasTags` trait).
- Necesitás permisos granulares por carpeta — esto requiere `entity_permissions` (RBAC feature), no viene incluido.

## Habilitar

1. `.env`:
   ```env
   INNERTIA_DIRECTORIES_ENABLED=true
   ```

2. Instalar (genera migration):
   ```bash
   php artisan innertia:directories:install
   ```

3. Migrar:
   ```bash
   php artisan migrate
   ```

4. Registrar rutas en `routes/api.private.php`:
   ```php
   Route::middleware(['auth:api', 'tenant.require'])->group(function () {
       \Innertia\Files\Directories\Routes::register();
   });
   ```

## Configurar owner_types

`owner_type` en la URL es el string corto configurado, no el FQCN:

```php
// config/innertia.php
'directories' => [
    'owner_types' => [
        'tenants'  => \App\Models\Tenant::class,
        'users'    => \App\Models\User::class,
        'orgs'     => \App\Models\Organization::class,
    ],
],
```

Ejemplo: `GET /directories/tenants/abc123/tree` usa la clave `tenants`.

## API del modelo

### Crear

```php
use Innertia\Files\Directories\Models\Directory;

// Crear en la raíz de un owner
$root = Directory::createIn(null, 'Proyectos', $tenant);

// Crear dentro de otra carpeta
$sub = Directory::createIn($root, 'Activos', $tenant);
```

### Navegar

```php
$dir->breadcrumbs();        // Collection<Directory> desde raíz hasta $dir (inclusive)
$dir->ancestors();          // Collection<Directory> desde raíz hasta el padre (no incluye $dir)
$dir->descendants();        // Collection<Directory> de todos los hijos recursivos

$dir->isAncestorOf($other); // bool
$dir->isDescendantOf($other); // bool
```

### Mutar

```php
$dir->rename('Nuevo nombre');
$dir->moveTo($newParent);       // mover a otra carpeta
$dir->moveToRoot();             // mover a raíz del mismo owner

$dir->trash();                  // soft-delete con trash_group_id
$dir->restoreFromTrash();       // restaura $dir y todos sus descendientes en group
$dir->forceDelete();            // hard delete irreversible
```

### Scopes

```php
Directory::roots()->get();                          // solo carpetas raíz (parent_id = null)
Directory::inOwner($tenant)->get();                 // por owner
Directory::descendantsOf($dir)->get();              // todos los descendientes
Directory::withTrashed()->inOwner($tenant)->get();  // incluye soft-deleted
```

## Eventos disponibles

El feature despacha 6 eventos tipados vía `DirectoryEvent` enum:

```php
namespace Innertia\Files\Directories\Events;

enum DirectoryEvent: string implements \Innertia\Platform\Events\DomainEventKey
{
    case Created    = 'directories.created';
    case Renamed    = 'directories.renamed';
    case Moved      = 'directories.moved';
    case Trashed    = 'directories.trashed';
    case Restored   = 'directories.restored';
    case HardDeleted = 'directories.hard_deleted';

    public function key(): string { return $this->value; }
}
```

### Suscribirse

```php
// AppServiceProvider::boot()
use Innertia\Files\Directories\Events\DirectoryEvent;
use Innertia\Facades\Innertia;

Innertia::events()->listen(DirectoryEvent::Created, function ($event) {
    Log::info("Carpeta creada: {$event->directory->name}");
});

Innertia::events()->listen(DirectoryEvent::Trashed, SendTrashNotification::class);
```

### Payload de cada evento

| Evento | Datos en el evento |
|---|---|
| `Created` | `$event->directory` |
| `Renamed` | `$event->directory`, `$event->oldName` |
| `Moved` | `$event->directory`, `$event->oldParentId` |
| `Trashed` | `$event->directory`, `$event->groupId` |
| `Restored` | `$event->directory`, `$event->restoredCount` |
| `HardDeleted` | `$event->directoryId` (modelo ya no existe) |

### Testing con fake

```php
use Innertia\Platform\Events\EventBusFake;

it('dispatches Created event', function () {
    $fake = EventBusFake::fake();

    Directory::createIn(null, 'Docs', $tenant);

    $fake->assertDispatched(DirectoryEvent::Created);
    $fake->assertDispatched(DirectoryEvent::Created, fn ($e) => $e->directory->name === 'Docs');
});
```

## HTTP endpoints

| Verbo | Path | Acción | Body |
|---|---|---|---|
| `GET` | `/directories/{ownerType}/{ownerId}/tree` | Árbol completo del owner | — |
| `GET` | `/directories/{ownerType}/{ownerId}` | Listar (raíces o hijos de `?parent_id=`) | — |
| `POST` | `/directories/{ownerType}/{ownerId}` | Crear carpeta | `{ "name": "...", "parent_id": null\|uuid }` |
| `GET` | `/directories/{ownerType}/{ownerId}/{id}` | Mostrar | — |
| `PATCH` | `/directories/{ownerType}/{ownerId}/{id}` | Renombrar | `{ "name": "..." }` |
| `POST` | `/directories/{ownerType}/{ownerId}/{id}/move` | Mover | `{ "parent_id": null\|uuid }` |
| `DELETE` | `/directories/{ownerType}/{ownerId}/{id}` | Enviar a trash | — |
| `POST` | `/directories/{ownerType}/{ownerId}/{id}/restore` | Restaurar desde trash | — |
| `DELETE` | `/directories/{ownerType}/{ownerId}/{id}/force` | Hard delete | — |
| `DELETE` | `/directories/{ownerType}/{ownerId}/trash` | Vaciar papelera completa del owner | — |

`{ownerType}` viene de `config('innertia.directories.owner_types')`.

## Extender controller (template method)

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Innertia\Files\Directories\Models\Directory;

class DirectoriesController extends \Innertia\Files\Directories\Http\Controllers\DirectoriesController
{
    protected function extraStoreRules(): array
    {
        return ['color' => 'nullable|string|max:7'];
    }

    protected function extraUpdateRules(): array
    {
        return ['color' => 'nullable|string|max:7'];
    }

    protected function extraFields(Request $request, ?Directory $directory = null): array
    {
        return ['color' => $request->input('color')];
    }
}

// routes/api.private.php
\Innertia\Files\Directories\Routes::register('directories', App\Http\Controllers\DirectoriesController::class);
```

## Extender modelo

```php
// config/innertia.php
'directories' => [
    'model' => App\Models\Directory::class,
],

// app/Models/Directory.php
class Directory extends \Innertia\Files\Directories\Models\Directory
{
    protected $fillable = [...parent::$fillable, 'color'];
}
```

## Scheduler — purge job

La purga automática de trash expirado se registra via el scheduler del producto:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('innertia:directories:purge-trash')->daily();
}
```

Por default elimina registros con `trashed_at` mayor a 30 días. Configurable:

```php
// config/innertia.php
'directories' => [
    'trash_ttl_days' => 30, // null = nunca purgar automáticamente
],
```

Ejecutar manualmente:

```bash
php artisan innertia:directories:purge-trash
php artisan innertia:directories:purge-trash --dry-run  # solo cuenta, no elimina
```

## Trash semántica — trash_group_id

Cuando se envía a trash una carpeta con hijos, todos reciben el mismo `trash_group_id`. Esto permite restaurar el árbol completo en una sola operación:

```php
$folder->trash();
// $folder->trash_group_id = 'abc-123'
// $folder->children[*]->trash_group_id = 'abc-123'  (recursivo)

$folder->restoreFromTrash();
// Restaura $folder + todos los descendientes con el mismo trash_group_id
```

Si se mueve **solo un hijo** a trash (sin su padre), recibe su propio `trash_group_id` — se restaura independientemente.

La lista de trash del owner agrupa visualmente por `trash_group_id` para mostrar "restaurar carpeta completa" vs. "restaurar ítem suelto" — igual que Google Drive.

## Performance

- **Materialized path**: `path` almacena el trail de IDs (`/abc/def/ghi/`). Queries de árbol usan `LIKE '/abc/def/%'` — aprovecha el índice de prefijo de `(owner_type, owner_id, path)`.
- Escala bien hasta ~100k directorios por tenant/owner. Sobre eso, considerar particionar por `tenant_id`.
- `descendants()` y `descendantsOf()` ejecutan una sola query LIKE — no queries recursivas (CTE).
- `breadcrumbs()` carga los ancestors desde el path almacenado con un único `whereIn` sobre IDs extraídos del path.

## Skills relacionados

- `innertia-events` — Event Bus tipado, DomainEventKey enums, EventBusFake para tests
- `innertia-tags` — si necesitás etiquetas transversales además de jerarquía
- `innertia-extending` — patrón template method para customizar controllers y modelos
- `innertia-config` — referencia de `config('innertia.directories')`
