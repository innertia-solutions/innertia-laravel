<?php

namespace Innertia\Files\Drive;

use Innertia\Files\Providers\ProviderConnection;

/**
 * Drive con la conexión OAuth de un usuario (Fase 1 de proveedores). Se implementa en un
 * spec posterior; existe para fijar el contrato.
 */
class OAuthDriveSource implements DriveSource
{
    public function __construct(public readonly ProviderConnection $connection) {}

    public function listChildren(string $folderId = 'root', ?string $driveId = null, bool $foldersOnly = false): array { throw $this->pending(); }
    public function sharedDrives(): array { throw $this->pending(); }
    public function metadata(string $id): DriveItem { throw $this->pending(); }
    public function path(string $id): array { throw $this->pending(); }
    public function download(string $id, int $maxBytes): string { throw $this->pending(); }
    public function export(string $id, string $mime): string { throw $this->pending(); }
    public function createFolder(string $name, string $parentId): DriveItem { throw $this->pending(); }

    private function pending(): \BadMethodCallException
    {
        return new \BadMethodCallException('OAuthDriveSource aún no está implementado.');
    }
}
