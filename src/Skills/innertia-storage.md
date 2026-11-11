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
$file->url()               // /files/{id}/download — siempre forzado como descarga
$file->viewUrl()           // /files/{id}/view — inline (PDF/imagen en browser)
$file->signedViewUrl()     // URL firmada de DOMINIO PROPIO — inline
$file->signedDownloadUrl() // URL firmada de dominio propio — descarga
$file->temporaryUrl(60)    // URL firmada del DRIVER (S3/R2, expone el proveedor) — bypasses permission check
```

Las rutas de serving (`/view` y `/download`) se registran automáticamente por el ServiceProvider.
No requieren `Routes::register()`.

### URLs firmadas de dominio propio (agnóstico de disk + proveedor)

`signedViewUrl()`/`signedDownloadUrl()` generan una URL de **tu dominio** con firma HMAC +
expiración (`config('innertia.files.url_ttl')`, default 15 min). **La firma ES la credencial**:
`FileController` sirve el archivo con una firma válida sin usuario autenticado, así funciona en
`<img>`/`<iframe>`/`fetch` (que no mandan Bearer). La ruta streamea desde `Storage::disk($file->disk)`,
así que es agnóstica del disk (local en dev, bucket en prod) y **nunca expone la URL del proveedor**.
El `FileResource` (`view_url`/`download_url`) ya emite estas URLs firmadas.

Firma inválida/vencida → 403; sin firma → cae a auth por usuario (401 si no hay sesión).
A diferencia de `temporaryUrl()` (presigned del driver, expone el bucket), esta mantiene todo
tras tu dominio — apto para CDN/Worker por delante.

### Tres modos de URL (según el caso de uso)

| Caso | Visibilidad | URL en el `FileResource` | Vence |
|---|---|---|---|
| Previsualizar / servir privado | auth/restricted | firmada corta (`url_ttl`, 15min) | sí |
| Público permanente (firma de correo, web) | **public** | estable `viewUrl()` **sin firma** | no |

Para **compartir** con TTL a elección: `GET /files/{id}/share-link?hours=N[&download=1]`
(controller CRUD) → `{ url, expires_in_hours }`. Cambiar a público: `PATCH /files/{id}`
con `{ visibility: 'public' }`.

### Serving seguro (Content-Disposition + headers)

`GET /files/{id}/view` sirve **inline** solo tipos seguros (imagen, PDF, video, audio, texto
plano); HTML/SVG/XHTML se fuerzan a `attachment` (anti-XSS). `GET /files/{id}/download` siempre
`attachment`. Todo lleva `X-Content-Type-Options: nosniff`. Cache: público → `public, max-age`;
privado → `no-store`.

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

## Versionamiento de contenido (siempre activo)

El `File` es la **versión vigente**; `file_versions` guarda las **superadas** (append-only). El `id`
nunca cambia: dueños, permisos, tags y URLs firmadas siguen apuntando al archivo y sirven lo vigente.

- Migración del núcleo `2025_01_03_000001_add_file_versions` (app + saas). Productos existentes:
  `php artisan migrate`. Sin migrar, las subidas siguen funcionando; el reemplazo, el historial
  (`GET files/{id}/versions`) y la restauración responden 422 (`FileVersioningNotMigratedException`)
  y el serving de versiones responde 404 — nunca 500 ni SQL. `FileVersioning::ready()` cachea solo el resultado positivo
  (un worker iniciado antes de `migrate` lo detecta sin reiniciarse).
- **Único camino** para cambiar bytes: `ReplaceFileContents($file, $localPath, FileVersionSource::X,
  mimeType:, extension:, performedBy:, meta: [...])` → `FileVersionResult` (`created` | `unchanged`).
  - Mismo checksum = `unchanged`: no escribe bytes ni crea versión, pero igual persiste
    `external_revision` / `external_export_mime` / `external_synced_at` de `meta`.
  - Bytes nuevos siempre en ruta nueva; archiva + actualiza en transacción con `lockForUpdate`; si la
    BD falla, borra los bytes nuevos. El nombre no cambia salvo `meta['original_name']`.
  - La extensión se normaliza (minúsculas, sin punto, solo `[a-z0-9]`, máx. 20). Resolución del mime:
    explícito (`mimeType:`) → el actual si la extensión no cambió → inferido por extensión → detectado
    por contenido (sniff).
  - `FileVersionCreated` y la poda corren en `DB::afterCommit`: si el llamador hace rollback de su
    transacción externa no hay evento, y los bytes nuevos pueden quedar huérfanos en el disk
    (limitación aceptada, basura inofensiva). La poda es best-effort (si falla, `FilesLog::warning`).
  - El checksum al crear/subir un archivo es best-effort; los archivos previos lo calculan de forma
    perezosa en el primer reemplazo.
- **Restaurar:** `RestoreFileVersion($file, $n)` crea una versión nueva (`source=restore`,
  `restored_from_version=n`); el historial no se edita.
- **Retención:** `config('innertia.files.versions.keep')` (`INNERTIA_FILES_VERSIONS_KEEP`; vacío = todas).
- **Borrado definitivo** (`forceDelete`, vaciar papelera) elimina bytes y filas de todas las versiones.
  La papelera no toca versiones.
- **Eventos:** `FileEvent::VersionCreated`, `FileEvent::VersionRestored`.
- **Serving:** `GET /files/{id}/versions/{n}/view|download` (mismo acceso y firma que el archivo;
  `FileVersion::signedDownloadPath()`). El filename del `Content-Disposition` se sanitiza.
  - **El historial de un archivo público NO es público:** solo la versión vigente sigue
    `visibility=public`. Una versión superada exige firma válida o usuario autenticado (`public` se
    trata como `auth`; `restricted` sigue exigiendo `isAccessibleBy`) y nunca lleva caché pública.
  - `{n}` está acotado a 1–9 dígitos (`[0-9]{1,9}`); fuera de rango → 404. Igual en `restore`.
  - Archivo en papelera → el serving responde 404, y el historial (`GET files/{id}/versions`)
    devuelve `download_url: null` en todas sus entradas.
- **API** (en `Routes::register()`): `GET files/{id}/versions`, `POST files/{id}/versions/{n}/restore`,
  `POST files/{id}/contents` (multipart `file`). Errores de dominio → 404/422 con mensaje (bytes de la
  versión ausentes = `FileVersionBytesMissingException`, 422); errores de BD → 500 genérico (el
  mensaje SQL nunca llega al cliente).
- **FileResource:** `version`, `versions_count`, `version_source`, `provider`, `external_url`,
  `external_synced_at`, `can_refresh`. `versions_count` es el **total incluyendo la vigente**
  (superadas + 1) y solo aparece si la query usó `withCount('versions')`.
- **Subclases de `File`** no deben pisar los casts del padre (`size`, `version`,
  `restored_from_version`, `version_created_at`, `external_synced_at` viven en `File::$casts`).
  Redeclarar `protected $casts = [...]` **reemplaza** el array entero: repetir ahí los del padre, o
  mejor declarar los propios en el método `casts()` (Laravel los mergea con el `$casts` heredado;
  si una clase intermedia define `casts()`, usar `array_merge(parent::casts(), [...])`).
- `File::versions()` **no tiene orden**: ordenar en el sitio de uso (`->orderByDesc('version')`).
- **Uso de almacenamiento:** `StorageUsage::totals(?scope)` → `current_bytes`, `versions_bytes`, `total_bytes`
  (incluye archivos en papelera).
- **Migración de disk:** `innertia:storage:migrate-files` también migra los bytes de `file_versions`.
  La fila se actualiza condicionalmente (mismo `disk` y `path` copiados): si cambió durante la copia
  (reemplazo concurrente) se omite, no se borra el origen y se cuenta en "omitidos por cambio
  concurrente".
- **Nunca** escribir `files.path`/`size`/`checksum` a mano para cambiar contenido: usar `ReplaceFileContents`.

## Importar desde Google Drive

Importa archivos y carpetas de Drive a `Files`/`Directories` y permite refrescar un archivo ya importado.

### Activación

En el grupo de rutas del producto (con su middleware de permisos):
`\Innertia\Files\Routes::registerDriveImport($prefix, $browseController, $importsController)`.
Rutas: `GET drive/browse`, `GET drive/items/{id}`, `POST drive/folders`, `POST files/imports`,
`GET files/imports/{id}`, `POST files/{id}/refresh`. Los controllers son sobrescribibles.

### Credencial

`DriveSource` + `DriveSourceResolver`: el producto puede bindear su propio `DriveSource` en el
container; si no, se usa la cuenta de servicio de Workspace (`workspace.google.service_account_json`)
impersonando al usuario de `config('innertia.files.drive.subject_setting')` o, si está vacío,
`workspace.google.admin_email`. Requiere **delegación a nivel de dominio** con el scope
`https://www.googleapis.com/auth/drive` y la **Drive API habilitada** en el proyecto de GCP.
`OAuthDriveSource` es solo contrato (no implementado).

### Proceso y cola

- Tabla `file_imports` (`FileImport`): `kind` import|refresh, `status` pending→running→done|failed,
  contadores (`found/imported/updated/unchanged/skipped/failed_count`), `items` con el motivo por
  ítem, `error`, `trace_id`. Migración `2025_01_03_000002_add_drive_import` (agrega también
  `provider`/`external_*` a `files` si faltan, y `provider`/`external_id` a `directories`).
- Columnas de procedencia en `directories`: si el producto tiene su propia migración de
  `directories` y corre **después** de la de la lib (p. ej. en `migrate:fresh`), la tabla queda sin
  `provider`/`external_id`. Agrégalas a esa migración (como hace `innertia:directories:install`).
  Mientras falten, `POST files/imports` responde 422 si la selección trae carpetas o hay
  `target_directory_id`, y un import con carpetas en el plan queda `failed` con ese mismo mensaje
  (`DriveDirectoryMapper::schemaReady()`, cachea solo el positivo).
- `ImportFromDrive` / `RefreshFromProvider` corren en la cola `innertia.files.drive.queue` con
  `$timeout = job_timeout` y `tries = 1`. Son idempotentes: el claim `pending→running` es atómico y
  solo corre desde `pending`.
- El `retry_after` de la conexión de cola **debe ser mayor** que `job_timeout`; si no, la cola
  reentrega el job mientras sigue corriendo. Usar un worker/cola dedicado.
- Locks por ítem/carpeta: duran `max(300, job_timeout)`; la espera es `files.drive.lock_wait`
  (60 s). Al vencer (LockTimeout) un archivo queda `skipped`; una carpeta queda `failed` y todo su
  contenido `skipped`. En multi-host el cache store debe
  soportar **locks atómicos compartidos** (redis o database, no file/array).
- Worker muerto: `failed()` del job marca `failed`; si no corrió, `GET files/imports/{id}` lo marca
  `failed` de forma perezosa pasado `job_timeout + 300` s.
- Mensajes de error (`error` y `reason` por ítem): solo se guardan los de Drive (`DriveException`)
  y de dominio conocidos (Directories, carpeta sin nombre libre, versionamiento sin migrar, lock);
  cualquier otro (SQL, disks, rutas) queda como "Error interno al importar el elemento (ver
  registro con el trace_id)." y el detalle completo va a `FilesLog` con el `trace_id`
  (`ImportErrors::publicMessage`).
- Falla de autenticación/conexión con Drive: el import se detiene (`failed`). Si el directorio
  destino desapareció: `failed`.

### Reglas

- Las carpetas se recrean como `Directories` (requiere el feature; si no, 422). Si el producto
  entrega owner (`importAttributes`), se aplica a toda carpeta y archivo creado; si no, cada carpeta
  hereda el owner de su padre (raíz/destino), pero los **archivos quedan sin owner** (no heredan).
  Si una carpeta falla, no aborta: su contenido queda `skipped`.
- Los nativos se exportan a Office/PDF según `native_exports` (`native_format`); se omiten
  Forms/Sites/atajos, lo que supere `max_file_size` o los 10 MB de exportación de Drive, y los
  archivos con descarga deshabilitada (403 de Drive → `skipped`).
- `max_items` se valida antes de escribir nada.
- Reimportar al mismo directorio refresca en vez de duplicar; el dedupe se acota por
  `owner_type`/`owner_id` (mismo id de Drive bajo owners distintos nunca colisiona).
  Hermanos con el mismo nombre reciben el sufijo " (2)".
- Resiliencia HTTP: reintentos con backoff en 429/5xx/403 por rate-limit; 401 refresca el token una
  vez. `path()` solo nombra la raíz cuando se llega a ella.
- Comportamientos conocidos: reimportar tras **mover** el archivo localmente lo duplica (el dedupe
  es por directorio). Selecciones solapadas (una carpeta + un archivo o subcarpeta suya) **no**
  duplican ni se cuentan dos veces: cada id de Drive se planifica una sola vez, y lo seleccionado
  explícitamente gana, así que ese elemento queda en la raíz del destino (no anidado en su carpeta).

### Refresh

`POST files/{id}/refresh` compara la señal de revisión: nativos = `modifiedTime`, binarios =
`md5Checksum`/`headRevisionId`. Misma revisión = `unchanged` sin descargar; si cambió,
`ReplaceFileContents` con `provider_refresh` en el mismo formato de exportación. El nombre local
no cambia.

### Hooks del controller

`extraImportRules()` (reglas extra de validación), `importAttributes(Request)` (solo
`owner_type`/`owner_id` se respetan; se aplican a cada File/Directory creado) y
`ownsImport(Request, FileImport)` (por defecto cada usuario ve solo sus procesos).

### FileResource

`can_refresh` es true solo para un archivo de Drive (`provider=google_drive` con `external_id`),
no eliminado y con una fuente disponible.

### Config `innertia.files.drive`

`queue`, `job_timeout` (3600), `lock_wait` (60), `max_items` (2000), `max_file_size` (200 MB),
`list_max`, `disk`, `subject_setting`, `native_exports`.

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

## Disco cloud multi-proveedor (R2 / S3 / GCS desde la UI)

Opt-in con `INNERTIA_STORAGE_CLOUD=true` (`config('innertia.storage.cloud')`). Las credenciales viven
en Settings bajo `integrations.storage.*` (`provider` r2|s3|gcs, `bucket`, `region`, `endpoint`,
`access_key_id`, `secret_access_key` **encriptado**, `public_url`) y se cargan desde la UI del producto
(hub de integraciones), no desde env.

- Los tres proveedores usan el driver `s3` de Laravel: R2 (endpoint `https://<account>.r2.cloudflarestorage.com`,
  región `auto`), S3 (región obligatoria; endpoint solo para compatibles) y GCS (API XML interoperable,
  claves **HMAC**, endpoint `https://storage.googleapis.com`). Requiere `league/flysystem-aws-s3-v3` en el producto.
- Completo → `StorageManager::hydrateCloud()` registra `filesystems.disks.cloud` y (con `as_default`) lo deja
  como `filesystems.default`: todo upload nuevo va al bucket; lo antiguo se sigue leyendo de su disco (`files.disk`).
  Los workers re-hidratan antes de cada job (cambios en la UI sin reiniciar).
- `Storage::probe($settings?)` → escribe/lee/borra un objeto de prueba (para el botón "Validar").
- `Storage::localPath($disk, $path)` → ruta local legible para cualquier disco (remotos se copian a un temporal).
  Usarlo en vez de `Storage::disk()->path()` (que no existe en s3).
- `php artisan innertia:storage:migrate-files [--from=local,public] [--dry-run] [--delete-source]` copia los
  archivos locales existentes al cloud y actualiza su `disk` (idempotente; conserva el origen por defecto).

## Aprovisionamiento automático (GCS / R2)

`Storage::provision($provider, $input)` crea bucket + credencial S3 de **mínimo privilegio** a partir de una credencial ADMIN de un solo uso (no se persiste). Devuelve `['settings' => [...CLOUD_FIELDS], 'details' => [...]]`; el producto guarda `settings` por su vía (secretos encriptados) y valida con `Storage::probeWithRetry()` (las credenciales nuevas tardan segundos en propagarse).

| Proveedor | `$input` | Permisos de la credencial admin | Qué crea |
|---|---|---|---|
| `gcs` | `service_account_json`, `bucket`, `location?` (def. `US`), `project_id?` | Storage Admin + Storage HMAC Key Admin + Service Account Admin | bucket privado uniforme, SA `storage-<bucket>` con `roles/storage.objectAdmin` solo en el bucket, HMAC de esa SA. Sin Service Account Admin → HMAC de la propia admin (aviso en `details`). |
| `r2` | `api_token`, `bucket`, `account_id?` (si el token ve una sola cuenta), `location?` | Workers R2 Storage: Edit + API Tokens: Edit (o Account API Tokens: Edit) | bucket + token "Workers R2 Storage Bucket Item Write" solo sobre el bucket; access key = id del token, secret = sha256(valor). |

S3 no se aprovisiona (exigiría una credencial IAM con permisos de crear usuarios). Errores → `Innertia\Storage\Provisioning\ProvisioningException` con mensaje para la UI.
