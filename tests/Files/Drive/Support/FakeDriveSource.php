<?php

namespace Innertia\Tests\Files\Drive\Support;

use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Drive\DriveSource;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\DriveExportTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;
use Innertia\Files\Support\TempFile;

/** Drive en memoria: árbol de ítems + contenidos. Registra las llamadas en $calls. */
final class FakeDriveSource implements DriveSource
{
    /** @var array<string, DriveItem> */
    public array $items = [];
    /** @var array<string, list<string>> parentId => ids */
    public array $children = [];
    /** @var array<string, string> id => bytes (binarios) */
    public array $contents = [];
    /** @var array<string, array<string, string>> id => [exportMime => bytes] */
    public array $exports = [];
    /** @var list<string> ids cuyo export Google rechaza por tamaño */
    public array $exportTooLarge = [];
    /** @var list<string> ids que fallan al bajar con un error genérico */
    public array $broken = [];
    /** @var list<string> ids cuyo dueño deshabilitó la descarga */
    public array $downloadDisabled = [];
    /** @var array<string, \Throwable> id => error que lanza al bajar/exportar */
    public array $failures = [];
    /** @var list<string> */
    public array $calls = [];

    public function folder(string $id, string $name, string $parent = 'root'): DriveItem
    {
        return $this->put(new DriveItem($id, $name, DriveItem::FOLDER_MIME, parents: [$parent]), $parent);
    }

    public function file(string $id, string $name, string $bytes, string $parent = 'root', string $mime = 'text/plain'): DriveItem
    {
        $this->contents[$id] = $bytes;

        return $this->put(new DriveItem($id, $name, $mime, size: strlen($bytes), md5: md5($bytes), version: '1', webUrl: "https://drive/{$id}", parents: [$parent]), $parent);
    }

    /** @param array<string, string> $exports exportMime => bytes */
    public function native(string $id, string $name, string $kind, array $exports, string $parent = 'root', string $version = '1'): DriveItem
    {
        $this->exports[$id] = $exports;

        return $this->put(new DriveItem($id, $name, "application/vnd.google-apps.{$kind}", modifiedTime: self::stamp((int) $version), version: $version, webUrl: "https://docs/{$id}", parents: [$parent]), $parent);
    }

    /** modifiedTime determinista a partir del número de versión del fake. */
    private static function stamp(int $version): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', 1767225600 + $version * 60);
    }

    /** Simula una edición en Drive: nuevo contenido y nueva revisión (nativos: sube modifiedTime y version). */
    public function edit(string $id, string $bytes): void
    {
        $old = $this->items[$id];

        if ($old->isNative()) {
            $this->exports[$id] = array_map(fn () => $bytes, $this->exports[$id]);
            $version = (int) $old->version + 1;
            $this->items[$id] = new DriveItem($old->id, $old->name, $old->mime, modifiedTime: self::stamp($version), version: (string) $version, webUrl: $old->webUrl, parents: $old->parents);

            return;
        }

        $this->contents[$id] = $bytes;
        $this->items[$id] = new DriveItem($old->id, $old->name, $old->mime, size: strlen($bytes), md5: md5($bytes), version: (string) ((int) $old->version + 1), webUrl: $old->webUrl, parents: $old->parents);
    }

    public function remove(string $id): void
    {
        unset($this->items[$id]);
    }

    public function listChildren(string $folderId = 'root', ?string $driveId = null, bool $foldersOnly = false): array
    {
        $this->calls[] = "list:{$folderId}";
        $items = array_values(array_filter(array_map(fn ($id) => $this->items[$id] ?? null, $this->children[$folderId] ?? [])));

        return $foldersOnly ? array_values(array_filter($items, fn (DriveItem $i) => $i->isFolder())) : $items;
    }

    public function sharedDrives(): array
    {
        return [];
    }

    public function metadata(string $id): DriveItem
    {
        $this->calls[] = "meta:{$id}";

        return $this->items[$id] ?? throw DriveItemNotFoundException::for($id);
    }

    public function path(string $id): array
    {
        return [['id' => 'root', 'name' => 'Mi unidad']];
    }

    public function download(string $id, int $maxBytes): string
    {
        $this->calls[] = "download:{$id}";
        if (isset($this->failures[$id])) {
            throw $this->failures[$id];
        }
        if (in_array($id, $this->downloadDisabled, true)) {
            throw \Innertia\Files\Drive\Exceptions\DriveItemNotImportableException::downloadDisabled();
        }
        if (in_array($id, $this->broken, true)) {
            throw new DriveException('Google Drive no permitió descargar el archivo: boom');
        }
        $bytes = $this->contents[$id] ?? throw DriveItemNotFoundException::for($id);
        if (strlen($bytes) > $maxBytes) {
            throw new DriveFileTooLargeException($maxBytes);
        }

        return $this->temp($bytes);
    }

    public function export(string $id, string $mime): string
    {
        $this->calls[] = "export:{$id}:{$mime}";
        if (isset($this->failures[$id])) {
            throw $this->failures[$id];
        }
        if (in_array($id, $this->exportTooLarge, true)) {
            throw new DriveExportTooLargeException();
        }

        return $this->temp($this->exports[$id][$mime] ?? throw new DriveException("Formato {$mime} no disponible"));
    }

    public function createFolder(string $name, string $parentId): DriveItem
    {
        return $this->folder('new-' . count($this->items), $name, $parentId);
    }

    private function put(DriveItem $item, string $parent): DriveItem
    {
        $this->items[$item->id] = $item;
        $this->children[$parent][] = $item->id;

        return $item;
    }

    private function temp(string $bytes): string
    {
        $path = TempFile::create();
        file_put_contents($path, $bytes);

        return $path;
    }
}
