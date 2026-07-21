<?php

namespace Innertia\Files\Providers;

use Innertia\Auth\Models\User;

interface FileProvider
{
    public function key(): string;

    public function connectRedirectUrl(string $scope, ?string $tenantId): string;

    /** @param array $callback full request query (code, state, ...) */
    public function handleCallback(array $callback, string $scope, ?string $tenantId): ProviderConnection;

    public function disconnect(ProviderConnection $conn): void;

    public function connectionFor(string $scope, ?User $user, ?string $tenantId): ?ProviderConnection;

    public function pickerConfig(ProviderConnection $conn): array;

    public function ensureAppFolder(ProviderConnection $conn): string;

    public function upload(ProviderConnection $conn, string $folderId, string $name, string $bytes, string $mime): array;

    public function download(ProviderConnection $conn, string $externalId): string;

    public function metadata(ProviderConnection $conn, string $externalId): array;

    public function share(ProviderConnection $conn, string $externalId, array $emails): void;
}
