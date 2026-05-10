<?php

namespace Innertia\Olimpo\Contracts;

interface OlimpoHandler
{
    public function health(): array;

    public function createTenant(array $data): array;

    public function getTenant(string $externalId): array;

    public function deleteTenant(string $externalId): array;

    public function suspendTenant(string $externalId): array;

    public function reactivateTenant(string $externalId): array;

    public function updateTrial(string $externalId, array $data): array;

    public function flushCache(string $externalId): array;

    public function getTenantUsers(string $externalId): array;

    public function impersonate(string $externalId, string $userId): array;

    public function getTenantBackups(string $externalId): array;

    public function createBackup(string $externalId): array;
}
