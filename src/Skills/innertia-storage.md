---
name: innertia-storage
description: Use when working with files, attachments, storage disks, avatars, or the HasSingleFile / HasFiles traits del paquete innertia-laravel. Trigger for "subir archivo", "avatar", "attachment", "storage disk", "File model", or "HasSingleFile".
---

# Innertia Storage — Files, HasSingleFile, HasFiles

El paquete provee una capa sobre `Illuminate\Filesystem` para manejar archivos asociados a modelos: un modelo `File`, dos traits (`HasSingleFile`, `HasFiles`), y una integración con los disks de Laravel.

## Modelo File

`\Innertia\Files\Models\File` — la entidad central. Schema típico:

```
files (id uuid PK, tenant_id, disk, path, original_name, mime, size, meta jsonb, created_by, timestamps)
```

- `disk` — nombre del disk de Laravel (`local`, `s3`, etc.)
- `path` — ruta relativa dentro del disk
- `meta` — bag JSON para datos custom (dimensiones, hash, tags)

Accesores útiles:

```php
$file->url        // URL absoluta resolvida via Storage::disk($disk)->url($path)
$file->stream()   // Stream para download
$file->contents() // Bytes en memoria (cuidado con archivos grandes)
$file->exists()   // Verifica en el disk
```

## Trait HasSingleFile (relación 1:1)

Cuando un modelo tiene UN archivo principal (avatar, isologo):

```php
use Innertia\Platform\Traits\HasSingleFile;

class Worker extends Model {
    use HasSingleFile;
}

// Accesor automático
$worker->file       // ?File
$worker->file?->url // URL para el frontend
```

Cómo asociar:

```php
$worker->setFile($uploadedFile, disk: 's3');
$worker->clearFile();   // soft-detach
$worker->deleteFile();  // hard-delete del archivo en el disk + del registro File
```

## Trait HasFiles (relación 1:N)

Cuando un modelo tiene MUCHOS archivos (documento con múltiples revisiones, project con anexos):

```php
use Innertia\Platform\Traits\HasFiles;

class Document extends Model {
    use HasFiles;
}

$document->files                              // Collection<File>
$document->addFile($uploadedFile, ['tag' => 'evidence']);
$document->removeFile($fileId);
$document->files()->where('meta->tag', 'evidence')->get();
```

## Storage disks

Los archivos viven en disks definidos en `config/filesystems.php`. El paquete no impone disks particulares — usá los de Laravel:

```php
// config/filesystems.php
'disks' => [
    'local' => [...],
    's3' => [
        'driver' => 's3',
        'key'    => env('AWS_ACCESS_KEY_ID'),
        ...
    ],
    'tenant-files' => [
        'driver' => 's3',
        'bucket' => env('TENANT_FILES_BUCKET'),
    ],
],
```

Y elegís cuál usar al asociar el archivo:

```php
$worker->setFile($upload, disk: 'tenant-files');
```

## Pattern: file upload en un controller

```php
public function uploadAvatar(Request $request, string $id): JsonResponse {
    $data = $request->validate([
        'avatar' => 'required|image|max:2048',   // 2MB
    ]);

    $worker = Worker::findOrFail($id);
    $worker->setFile($data['avatar']);

    return response()->json(['url' => $worker->file?->url]);
}
```

## Pattern: download autorizado

```php
public function download(string $fileId) {
    $file = File::findOrFail($fileId);

    Gate::authorize('view', $file);   // tu propio DomainGate

    return Storage::disk($file->disk)->download($file->path, $file->original_name);
}
```

## Cleanup automático

Cuando se elimina el modelo padre, los traits NO eliminan los archivos automáticamente — es decisión consciente para evitar pérdida de data. Si querés cleanup, override en el Observer:

```php
class WorkerObserver {
    public function deleted(Worker $worker) {
        $worker->deleteFile();   // explícito
    }
}
```

O usá `forceDelete()` para soft-deleted models que ya están confirmados a borrar.

## Disks per-tenant

Si querés disks por tenant (cada tenant tiene su bucket o subfolder), usá un disk dinámico:

```php
// app/Providers/AppServiceProvider.php
Storage::extend('tenant', function ($app, $config) {
    $tenant = Innertia::tenant();
    return Storage::createS3Driver(array_merge($config, [
        'root' => "tenants/{$tenant?->key}/files",
    ]));
});

// config/filesystems.php
'tenant' => ['driver' => 'tenant', /* ... */],
```

Luego: `$worker->setFile($upload, disk: 'tenant');`.

## Exports

Los exports usan su propio disk via `config('innertia.exports.disk')`. El paquete persiste ZIPs ahí. Ver skill `innertia-config`.

## Skills relacionados

- `innertia-config` — bloque `exports`, mail logo url
- `innertia-framework` — convención de modelos en Domains/
