# Changelog

All notable changes to `innertia-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### BREAKING CHANGES
- **DomainEvent contract unified.** Removed `DomainEvent::webhookKey()` method and `const KEY` convention.
  Every event must now implement `public function key(): DomainEventKey` returning an enum case.
  `resolvedKey()` is now final, derived from `key()` + optional `variant()`.
  Subscribers using `Event::listen('event.string.key', ...)` continue working — new typed API is recommended.

### Added
- `Innertia\Platform\Events\DomainEventKey` — interface implemented by event-key enums.
- `Innertia\Platform\Events\Trigger` — explicit contract for event handlers.
- `Innertia\Platform\Events\EventBus` — typed listener registry over Laravel events.
- `Innertia\Platform\Events\EventBusFake` — test helper with assertion methods (assertDispatched, assertNotDispatched, etc.).
- `Innertia\Platform\Events\IsDomainEvent` — marker interface enabling Laravel listener subscription to all DomainEvent subclasses.
- `Innertia\Facades\Events` facade and `Innertia::events()` static helper.
- Event catalog introspection via `Innertia::events()->catalog()`.
- **Tags feature** (opt-in): polymorphic, tenant-scoped tagging system. Enable with `INNERTIA_TAGS_ENABLED=true` + `php artisan innertia:tags:install`. Apply `HasTags` trait on any model. See `docs/superpowers/specs/2026-05-26-innertia-tags-design.md`.

### Files lifecycle (core)
- File model now uses `SoftDeletes` — `delete()` is soft (preserves storage). Use `forceDelete()` for permanent removal.
- **BREAKING:** `File::delete()` no longer removes the physical file. Use `forceDelete()` or `trash()` + `purge-trash` command for explicit lifecycle.
- **BREAKING:** Inline view URL moved from `/files/{id}` to `/files/{id}/view`. Named route `innertia.files.view` is stable. Use `route('innertia.files.view', $id)` or `$file->viewUrl()` instead of hardcoded paths.
- File model uses `HasTags` trait — files can be tagged out-of-the-box when Tags feature is active.
- 6 typed events: `files.uploaded`, `.renamed`, `.moved`, `.trashed`, `.restored`, `.hard_deleted`.
- New use cases: `UploadFile`, `RenameFile`, `MoveFile`, `TrashFile`, `RestoreFile`, `HardDeleteFile`, `EmptyFilesTrash`.
- `innertia:files:purge-trash` artisan command with `INNERTIA_FILES_TRASH_RETENTION_DAYS` config.
- HTTP endpoints via `\Innertia\Files\Routes::register()`: list/upload/rename/move/delete/restore/trash.

### Files ↔ Directories integration
- `directory_id` column added to files via `innertia:directories:install` (idempotent).
- `$file->moveTo($directory)`, `$file->moveToRoot()`, `$file->directory()` relation.
- `$directory->files()` relation.
- Directory trash cascades to files (shared `trash_group_id` for grouped restore).
- New endpoints: `GET /directories/{id}/files`, `POST /files` accepts `directory_id`, `PATCH /files/{id}` accepts `directory_id` for move.
- Files trashed independently keep their own group — restoring the directory doesn't restore them.

### Added (Directories feature)
- **Directories feature** (opt-in): polymorphic owner-scoped tree with materialized path, soft delete with trash_group_id for Drive-style grouped restore, 6 dispatched events (DirectoryEvent enum). Enable with `INNERTIA_DIRECTORIES_ENABLED=true` + `php artisan innertia:directories:install`. Apply via `Directory::createIn($parent, $name, $owner)`. See `docs/superpowers/specs/2026-05-26-innertia-directories-design.md`.
- `Innertia\Files\Directories\DirectoriesFeature` gate, install command, purge-trash artisan command.
- `Innertia\Files\Directories\Models\Directory` model with tree navigation (descendants, ancestors, breadcrumbs).
- 7 use cases (Create/Rename/Move/Trash/Restore/HardDelete/EmptyTrash).
- `Routes::register()` opt-in HTTP layer with CRUD, restore, trash, /tree (via DataTree).
- 6 typed events: `directories.created`, `.renamed`, `.moved`, `.trashed`, `.restored`, `.hard_deleted`.

### Changed
- 5 Workflow events refactored to new `key()` / `variant()` contract.
- `WorkflowEvent` enum implements `DomainEventKey`; `WorkflowEvent::forStep()` removed (replaced by `DomainEvent::variant()`).
- `WebhookService::dispatchForEvent` uses `$event->resolvedKey()` instead of `webhookKey()`.

### Upgrade guide
See `docs/superpowers/specs/2026-05-26-innertia-event-bus-design.md` for the full migration guide.

Quick path:
1. Define an enum implementing `DomainEventKey` listing your event cases.
2. In each event class, replace `const KEY = '...'` with `public function key(): DomainEventKey { return MyEnum::Case; }`.
3. If you had a custom `resolvedKey()` returning `Enum::Case->forStep($x)` or similar, implement `variant(): ?string { return $x; }`.
4. Replace `Event::listen('event.key', ...)` with `Innertia::events()->listen(MyEnum::Case, ...)` (optional but recommended).

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
- Organizations feature is forcibly inactive in `api` mode regardless of
  `organizations.enabled` — API consumers manage their own isolation.
  Centralised via `Innertia\Platform\Organizations\OrganizationsFeature::isActive()`.
