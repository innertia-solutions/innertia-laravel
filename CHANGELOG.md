# Changelog

All notable changes to `innertia-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] — 2026-05-23

### Added
- **Organizations** opt-in layer (off by default):
  - `Innertia\Platform\Organizations\Models\Organization` — concrete base
    Eloquent model (`id`, `tenant_id`, `name`, `key`, `active`, timestamps),
    with `(tenant_id, key)` unique. Auto-loaded migration + publishable via
    the existing `innertia-migrations` tag. Apps can extend it or replace it.
  - `Innertia\Database\Factories\OrganizationFactory`.
  - `Innertia\Platform\Contracts\OrganizationContract` — optional interface
    for apps that prefer typing by contract or fully replacing the model.
  - `Innertia\Platform\Organizations\OrganizationContext` with `current()` /
    `scope()` / `withOrganization()`.
  - `Innertia\Facades\Innertia::organization()` accessor.
  - `Innertia\Platform\Traits\HasOrganization` (creating + global scope).
  - `Innertia\Platform\Organizations\Middleware\ResolveOrganizationFromHeader`
    and `RequireOrganization`, registered as `organization.resolve` /
    `organization.require` when enabled.
  - `php artisan innertia:organization:install` — generates the consolidated
    migration that adds `organization_id` to declared tables + `roles` +
    `model_roles`.
  - `php artisan innertia:organization:check` — CI guard.
  - `Role::findByName/findByNameOrFail/createUnique` now accept an
    `?int $organizationId` arg with global-fallback resolution.
  - `HasRoles::hasRole` and `HasRoles::assignRole` now scope by
    `model_roles.organization_id`.
  - `PermissionsService` cache keys include the active organization id when
    the feature is enabled.
- Composer scripts `test:disabled`, `test:enabled`, `test` for the dual suite.
- `docs/organizations.md` adoption guide.

### Changed
- `InnertiaManager` constructor now accepts an optional
  `OrganizationContext`. Existing app-mode and saas-mode (org-disabled)
  behaviour is byte-identical.

### Notes
- Apps that have already published `config/innertia.php` must add the
  `organizations` block manually OR re-publish with `--force`.
- `user_apps` table is unchanged — app-access is orthogonal to organizations.
