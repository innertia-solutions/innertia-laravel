---
name: innertia-storage
description: Use when working with files, storage, trash/restore lifecycle, file tagging, visibility/permissions, directory integration, or the HasSingleFile / HasFiles traits. Trigger for "subir archivo", "papelera", "File model", "HasSingleFile", "HasFiles", "storage disk", "attachment", "avatar", "file lifecycle", or "file events".
---

# Innertia Storage — Files Lifecycle

El paquete provee un sistema completo de gestión de archivos: upload, storage, papelera, tags,
eventos tipados e integración con Directorios. Files es **core** — siempre activo, sin feature flag.

## Cuándo usar

| Necesidad | Solución |
|---|---|
| Subir y guardar un archivo | `File::fromRequest()` / `UploadFile` use case |
| Papelera (soft delete) | `$file->trash()` / `$file->restoreFromTrash()` |
| Borrado permanente | `$file->forceDelete()` |
| Taggear archivos | `$file->tag('factura')` — requiere `INNERTIA_TAGS_ENABLED=true` |
| Árbol de carpetas | `$file->moveTo($dir)` — requiere DirectoriesFeature activa |
| Endpoints CRUD HTTP | `\Innertia\Files\Routes::register()` en las rutas privadas |

## Habilitar

**Files es core** — no tiene feature flag. Está activo desde que se aplica el paquete.

Para funcionalidades adicionales:
- **Tags en archivos**: `INNERTIA_TAGS_ENABLED=true` + `php artisan innertia:tags:install`
- **Directorios**: `INNERTIA_DIRECTORIES_ENABLED=true` + `php artisan innertia:directories:install`
  (este comando también agrega `directory_id` a la tabla `files`)

## Modelo File

`\Innertia\Files\Models\File` — entidad central. Traits aplicados:

- `SoftDeletes` — `delete()` es soft (preserva storage). Usar `forceDelete()` para borrar storage.
- `HasTags` — tagging cuando TagsFeature activa.
- `HasEntityPermissions` — grants por usuario/rol cuando visibility es `restricted`.

Schema:
```
files (
  id uuid PK,
  disk, path, original_name, mime_type, extension,
  size int, visibility string,
  owner_type, owner_id (morph),
  directory_id uuid nullable FK → directories,
  trash_group_id uuid nullable,
  created_by uuid,
  deleted_at timestamp,   ← soft delete
  timestamps
)
```

### API del modelo

```php
// Lifecycle
$file->trash()                          // soft delete → FileEvent::Trashed
$file->restoreFromTrash($dirId?)        // restore → FileEvent::Restored
$file->forceDelete()                    // borra storage + DB row → FileEvent::HardDeleted
$file->rename('nuevo-nombre.pdf')       // → FileEvent::Renamed
$file->moveTo($directory)               // → FileEvent::Moved
$file->moveToRoot()                     // → FileEvent::Moved (directory_id = null)

// Relaciones
$file->directory()                      // BelongsTo Directory
$file->owner()                          // MorphTo (owner model)

// Helpers
$file->sizeMb()
$file->sizeKb()
$file->isImage()
$file->isPdf()
```

## Factories estáticas

```php
// Desde un request HTTP (field 'attachment')
$file = File::fromRequest($request, 'attachment', disk: 's3');

// Desde UploadedFile ya validado
$file = File::fromUploadedFile($uploadedFile, disk: 'tenant-files');

// Desde una ruta absoluta del filesystem local
$file = File::fromPath('/tmp/report.csv', visibility: 'auth');

// Desde URL remota (hace GET + persiste en el disk)
$file = File::fromUrl('https://example.com/data.xlsx', disk: 's3');
```

Todos crean el registro en DB + mueven el archivo al disk indicado.
Si no se especifica disk, usa `config('filesystems.default')`.

## Visibility model

| Valor | Quién accede |
|---|---|
| `public` | Cualquiera, sin autenticación |
| `auth` | Cualquier usuario autenticado (default) |
| `restricted` | Solo usuarios/roles con grants explícitos |

```php
// Restricción explícita
$file->allowUsers($user1, $user2);          // visibility → 'restricted'
$file->allowRoles('admin', 'manager');      // visibility → 'restricted'
$file->restrict(users: [$user1], roles: ['admin']);

// Check
$file->isAccessibleBy($user);  // true/false
```

`isAccessibleBy` para `restricted`: creator siempre tiene acceso, luego entity-level permissions,
luego cascade vía `$file->owner->canAccess($user)` si el owner implementa ese método.

## URLs

```php
$file->url()             // /files/{id}/download — siempre forzado como descarga
$file->viewUrl()         // /files/{id}/view — inline (PDF/imagen en browser)
$file->temporaryUrl(60)  // URL firmada del driver (S3/etc.) — bypasses permission check
```

Las rutas de serving (`/view` y `/download`) se registran automáticamente por el ServiceProvider.
No requieren `Routes::register()`.

> **BREAKING (migración):** El endpoint inline view cambió de `/files/{id}` → `/files/{id}/view`.
> Usar `route('innertia.files.view', $id)` o `$file->viewUrl()` — ambos son estables.

## HasSingleFile / HasFiles traits (legado — siguen funcionando)

Para modelos que necesitan asociar archivos directamente:

```php
// 1:1 (avatar, logo)
class Worker extends Model {
    use \Innertia\Platform\Traits\HasSingleFile;
}

$worker->file           // ?File
$worker->setFile($uploadedFile, disk: 's3');
$worker->clearFile();   // soft-detach
$worker->deleteFile();  // hard-delete storage + registro

// 1:N (documento con múltiples revisiones)
class Document extends Model {
    use \Innertia\Platform\Traits\HasFiles;
}

$document->files                               // Collection<File>
$document->addFile($uploadedFile);
$document->removeFile($fileId);
```

## HasTags integración

Cuando `INNERTIA_TAGS_ENABLED=true`, el modelo `File` tiene `HasTags` activo:

```php
$file->tag('factura', 'urgente');
$file->untag('urgente');
$file->tags;                        // Collection<Tag>

// Scopes en queries
File::withAnyTags(['factura', 'urgente'])->get();
File::withAllTags(['factura', 'urgente'])->get();
File::withoutTags(['borrador'])->get();
```

## Eventos

```php
use Innertia\Files\Events\FileEvent;

// 6 casos tipados
FileEvent::Uploaded    // 'files.uploaded'
FileEvent::Renamed     // 'files.renamed'
FileEvent::Moved       // 'files.moved'
FileEvent::Trashed     // 'files.trashed'
FileEvent::Restored    // 'files.restored'
FileEvent::HardDeleted // 'files.hard_deleted'

// Suscribir
Innertia::events()->listen(FileEvent::Uploaded, function (FileUploaded $event) {
    // $event->fileId, $event->originalName, $event->disk, $event->path
});

// Wildcard (todos los eventos de files)
Innertia::events()->listen(FileEvent::class, MyFileListener::class);
```

## HTTP endpoints CRUD

Registrar en las rutas privadas:

```php
// routes/api.private.php
Route::middleware(['auth:api', 'tenant.require'])->group(function () {
    \Innertia\Files\Routes::register();   // monta en /files
    // o con prefijo custom:
    \Innertia\Files\Routes::register('documentos', App\Http\DocumentosController::class);
});
```

| Método | Ruta | Acción |
|---|---|---|
| `GET` | `/files` | Lista paginada. Query: `trashed=only\|with`, `search`, `owner_type`, `owner_id`, `include=tags`, `per_page` |
| `POST` | `/files` | Upload. Body: `file` (multipart), `visibility?`, `owner_type?`, `owner_id?`, `directory_id?` |
| `GET` | `/files/{id}` | Detalle. Query: `include=tags` |
| `PATCH` | `/files/{id}` | Rename (`original_name`) + Move (`directory_id`, null = root) |
| `DELETE` | `/files/{id}` | Soft-delete (trash). Query: `?force=true` para hard-delete permanente |
| `POST` | `/files/{id}/restore` | Restaurar desde papelera. Body: `directory_id?` |
| `GET` | `/files/trash` | Lista sólo trashed, ordenado por `deleted_at` desc |
| `POST` | `/files/trash/empty` | Purge completo de papelera (hard-delete todos) |

## Endpoints de serving (siempre activos)

Registrados automáticamente por `InnertiaServiceProvider`, sin necesidad de `Routes::register()`:

| Ruta | Named route | Comportamiento |
|---|---|---|
| `GET /files/{id}/view` | `innertia.files.view` | Inline — `Content-Disposition: inline` |
| `GET /files/{id}/download` | `innertia.files.download` | Descarga forzada — `Content-Disposition: attachment` |

Ambos respetan `visibility` + `isAccessibleBy()`. Archivos trashed devuelven 404.

## Directorios integración

Cuando `DirectoriesFeature` está activa (`INNERTIA_DIRECTORIES_ENABLED=true`):

```php
// Mover archivo a carpeta
$file->moveTo($directory);     // actualiza directory_id → FileEvent::Moved
$file->moveToRoot();           // directory_id = null   → FileEvent::Moved

// Relación inversa
$directory->files();           // HasMany<File>

// Endpoint adicional
GET /directories/{id}/files    // lista archivos de esa carpeta
```

**Cascade trash desde Directory:** Cuando una carpeta se envía a la papelera, sus archivos activos
(no trashados independientemente) se trashean con el mismo `trash_group_id`. Al restaurar el
directorio, todos los archivos del mismo grupo se restauran juntos.

```php
// Archivos trashados antes que su directorio mantienen su propio grupo
// → restaurar el directorio NO los restaura
```

## Papelera lifecycle

```
upload
  └─► File (activo, storage presente)
        └─► trash()          → SoftDeletes, storage PRESERVADO, FileEvent::Trashed
              └─► restoreFromTrash()  → activo de nuevo, FileEvent::Restored
              └─► forceDelete()       → borra storage + DB row, FileEvent::HardDeleted
              └─► innertia:files:purge-trash (comando artisan)
                    → forceDelete() en todos los trashed que superan retention days
```

> **BREAKING:** `File::delete()` ahora es soft — el storage físico NO se borra.
> Usar `$file->forceDelete()` o el comando purge para borrado permanente.

## Retention / purge automático

```env
INNERTIA_FILES_TRASH_RETENTION_DAYS=30   # null = no auto-purge
```

```bash
php artisan innertia:files:purge-trash           # purga archivos en papelera > retention days
php artisan innertia:files:purge-trash --dry-run # preview sin borrar
```

Programar en `app/Console/Kernel.php`:
```php
$schedule->command('innertia:files:purge-trash')->daily();
```

## Performance / config

```php
// config/innertia.php
'files' => [
    'max_upload_mb' => env('INNERTIA_FILES_MAX_UPLOAD_MB', 20),
    'disk'          => env('INNERTIA_FILES_DISK', 'local'),
    'trash_retention_days' => env('INNERTIA_FILES_TRASH_RETENTION_DAYS', null),
],
```

Validation rule de upload aplicada automáticamente en `FilesController::store()`:
`'file' => ['required', 'file', 'max:' . (config('innertia.files.max_upload_mb') * 1024)]`

## Migration breaking change — resumen

| Comportamiento | Antes | Ahora |
|---|---|---|
| `File::delete()` | Borraba storage + DB | Soft delete (storage preservado) |
| Inline view URL | `/files/{id}` | `/files/{id}/view` — named route `innertia.files.view` |
| Hard-delete explícito | N/A | `$file->forceDelete()` |

## Skills relacionados

- `innertia-directories` — árbol de carpetas, materialized path, cascade trash
- `innertia-tags` — sistema de tags polimórfico, HasTags, scopes de query
- `innertia-events` — Event Bus tipado, DomainEventKey, listen/fake
- `innertia-permissions` — entity-level permissions, HasEntityPermissions
- `innertia-config` — referencia de `config/innertia.php`

## Sharing & Inherited Permissions (Sub-C)

### Directory access control

`Directory` uses `HasEntityPermissions` (same trait as `File`). Fluent API:

```php
$directory->grantAccessTo($user, 'access');  // or 'view', 'edit', 'manage'
$directory->revokeAccessFrom($user, 'access');
$directory->isAccessibleBy($user);           // true if any grant exists (action ignored — Drive-style OR logic)
```

`scopeAccessibleBy($user)` filters directories by direct grant OR grant on any ancestor directory (materialized path inheritance):

```php
Directory::accessibleBy($user)->get();
```

### File access sources

`File::isAccessibleBy($user)` returns `true` if any of the following:

1. File visibility is `public` or `auth` (existing behavior)
2. `created_by = user` — owner always has access (existing behavior)
3. Direct grant on the file via `entity_permissions` (existing behavior)
4. **NEW:** Grant on any ancestor directory of the file (materialized path inheritance)

OR logic — the most permissive grant wins. No restriction inheritance.

### Use cases

```php
// Share a directory with a user
(new ShareDirectory($directory, $user, 'access'))->execute();   // returns EntityPermission
(new RevokeDirectoryShare($directory, $user, 'access'))->execute();

// Share a file with a user
(new ShareFile($file, $user, 'access'))->execute();             // returns EntityPermission
(new RevokeFileShare($file, $user, 'access'))->execute();
```

Default action: `'access'`. Supported actions: `access | view | edit | manage`.

### HTTP endpoints

```
GET    /directories/{id}/grants        — list grants on a directory
POST   /directories/{id}/grants        — grant access: {grantable_type, grantable_id, action}
DELETE /directories/{id}/grants        — revoke access: {grantable_type, grantable_id, action}

GET    /files/{id}/grants              — list grants on a file
POST   /files/{id}/grants              — grant access: {grantable_type, grantable_id, action}
DELETE /files/{id}/grants              — revoke access: {grantable_type, grantable_id, action}

GET    /files/shared-with-me           — paginated list of files shared with the authenticated user
                                         (excludes files the user created themselves)
```

Body for POST/DELETE: `{ "grantable_type": "user", "grantable_id": "<uuid>", "action": "access" }`

Response for grant endpoints: `EntityPermissionResource` — `{id, entity_type, entity_id, grantable_type, grantable_id, action, created_at}`.

### Cleanup on HardDelete

`HardDeleteDirectory` calls `revokeAllEntityAccess()` on all affected directories (the target + all descendants) inside the DB transaction before `forceDelete()`. The `entity_permissions` table stays clean.

### Exception

`Innertia\Files\Exceptions\AccessDeniedException` — thrown when access is denied. Extend HTTP layer to catch and return 403 as needed.
