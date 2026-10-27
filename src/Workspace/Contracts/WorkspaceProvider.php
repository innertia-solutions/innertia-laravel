<?php

namespace Innertia\Workspace\Contracts;

/**
 * Administración de usuarios de un workspace externo (Google Workspace, Microsoft 365, …).
 * Cada provider mapea su API al shape común de usuario:
 *   {id, email, full_name, given_name, family_name, suspended, last_login_at, created_at, is_admin}
 */
interface WorkspaceProvider
{
    public function configured(): bool;

    /** @return array{ok:bool, message:string, details?:array} */
    public function validate(): array;

    /** @return array{users:array<int,array>, next_page_token:?string} */
    public function listUsers(?string $query = null, ?string $pageToken = null, int $max = 50): array;

    public function createUser(string $email, string $givenName, string $familyName, string $password, bool $changeNext = true): array;

    public function resetPassword(string $userKey, string $password, bool $changeNext = true): array;

    public function setSuspended(string $userKey, bool $suspended): array;

    public function deleteUser(string $userKey): void;
}
