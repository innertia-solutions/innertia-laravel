<?php

namespace Innertia\Files\Drive;

/**
 * Acceso a Google Drive con una credencial concreta (cuenta de servicio, OAuth…).
 * Toda falla de la API se lanza como Exceptions\DriveException (o subclase).
 */
interface DriveSource
{
    /**
     * Hijos de una carpeta ('root' = Mi unidad; id de unidad compartida = su raíz).
     * Pagina hasta el final; si supera config innertia.files.drive.list_max lanza DriveException.
     *
     * @return list<DriveItem>
     */
    public function listChildren(string $folderId = 'root', ?string $driveId = null, bool $foldersOnly = false): array;

    /** @return list<array{id: string, name: string}> */
    public function sharedDrives(): array;

    /** @throws Exceptions\DriveItemNotFoundException */
    public function metadata(string $id): DriveItem;

    /**
     * Ruta desde la raíz hasta el ítem EXCLUIDO ("Mi unidad" o el nombre de la unidad compartida primero).
     *
     * @return list<array{id: string, name: string}>
     */
    public function path(string $id): array;

    /**
     * Baja un binario a un temporal local (streaming). Lanza DriveFileTooLargeException si supera $maxBytes.
     *
     * @return string ruta local del temporal (el llamador la borra)
     */
    public function download(string $id, int $maxBytes): string;

    /**
     * Exporta un nativo al mime dado a un temporal local. Lanza DriveExportTooLargeException
     * cuando Google lo rechaza por tamaño (límite de 10 MB de la API).
     */
    public function export(string $id, string $mime): string;

    public function createFolder(string $name, string $parentId): DriveItem;
}
