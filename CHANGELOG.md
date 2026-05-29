# Changelog

All notable changes to `innertia-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### BREAKING CHANGES — API mode: Organizations (replaces Clients)

- **`Client` model removed** — replaced by `Organization` (`src/Api/Models/Organization.php`)
  - `organizations` table replaces `clients` table
  - Adds `parent_id` (nullable UUID) for hierarchical orgs — root orgs have `parent_id = null`
  - Uses `HasConfigs` trait for flexible per-org config
  - New helpers: `isRoot()`, `isChild()`, `ancestors()`, `children()`, `isActive()`, `isSuspended()`
  - `suspend()` / `reactivate()` replace direct status updates
- **`ClientApiKey` model removed** — replaced by `ApiKey` (`src/Api/Models/ApiKey.php`)
  - `api_keys` table replaces `client_api_keys` table
  - `organization_id` replaces `client_id`
  - No `permissions` array — keys are identity only
  - New `is_default` flag — set on the auto-generated key at org creation
  - `key_prefix` (12-char indexed prefix) for fast DB lookup before hash verification
- **`VerifyClientApiKey` middleware removed** — replaced by `VerifyApiKey`
  - Alias: `verify.api.key` (register in `InnertiaApiProvider::boot()`)
  - Injects `$request->attributes->get('organization')` and `$request->attributes->get('api_key')` (was `client` / `client_api_key`)
- **`RegisterClient` use case removed** — replaced by `RegisterOrganization` + `CreateChildOrganization`
- **`ApiPermissions` class removed** — api mode no longer has route-level permissions on keys
- **Routes changed**: `/olimpo/clients/*` → `/olimpo/organizations/*`
- **`api.available_permissions` config removed** — api mode keys have no permissions

### Added (api mode)

- `OrganizationCreated`, `OrganizationSuspended`, `OrganizationReactivated`, `ApiKeyCreated`, `ApiKeyRevoked` domain events via EventBus
- `configs` table migration added to api migrations (`database/migrations/api/`) — enables `HasConfigs` on organizations
- `CreateApiKey` use case — creates additional keys for an existing org
- `RevokeApiKey` use case — revokes a key and fires `ApiKeyRevoked` event

### BREAKING CHANGES — Apps → Contexts rename
- **`HasApps` trait renamed to `HasContexts`** — update `use HasApps` → `use HasContexts` in User + Tenant models.
- **Methods renamed**: `hasApp()→hasContext()`, `grantApp()→grantContext()`, `revokeApp()→revokeContext()`, `syncApps()→syncContexts()`, `appKeys()→contextKeys()`, `appKeysInOrganization()→contextKeysInOrganization()`, `accessibleOrganizationsByApp()→accessibleOrganizationsByContext()`.
- **`UserApp` model renamed to `UserContext`** (`Innertia\Auth\Models\UserContext`).
- **Table renamed**: `user_apps` → `user_contexts`. Column `app` → `context`.
- **Config key renamed**: `innertia.apps` → `innertia.contexts`.
- **Middleware alias renamed**: `app:backoffice` → `context:backoffice` (`AppMiddleware` → `ContextMiddleware`).
- **Login + auth request field**: `{ app: 'backoffice' }` → `{ context: 'backoffice' }`.
- **JWT claim**: `app` → `context`.
- **Backoffice routes**: `GET/POST/DELETE /users/{id}/apps` → `/users/{id}/contexts`.
- **`/auth/me` response**: removed legacy `availableContexts` alias.

### Sharing & inherited permissions (Sub-C)
- **Directory grants**: `Directory` now uses `HasEntityPermissions` — `grantAccessTo()`, `revokeAccessFrom()`, `isAccessibleBy()`.
- **`Directory::scopeAccessibleBy($user)`**: Eloquent scope that returns directories where the user has a direct grant or a grant on any ancestor (materialized path inheritance via `LIKE` query).
- **File inherits directory access**: `File::isAccessibleBy($user)` now checks ancestor directory grants via `inheritedDirectoryAccess()` — parsing the materialized path to find all ancestor IDs in one query.
- **`ShareDirectory` / `RevokeDirectoryShare`**: Use cases to grant/revoke access to a directory.
- **`ShareFile` / `RevokeFileShare`**: Use cases to grant/revoke access to a file.
- **`DirectoryGrantsController`**: `GET/POST/DELETE /directories/{id}/grants`.
- **`FileGrantsController`**: `GET/POST/DELETE /files/{id}/grants`.
- **`SharedFilesController`**: `GET /files/shared-with-me` — paginated files accessible via grant (excludes own files).
- **`EntityPermissionResource`**: JSON resource for entity_permissions rows.
- **`AccessDeniedException`**: Exception class for access control failures.
- **HardDeleteDirectory cleanup**: `revokeAllEntityAccess()` on target + descendants runs inside the DB transaction before `forceDelete()`.
- **Routes**: `Files\Routes::register()` and `Directories\Routes::register()` now include grants + shared-with-me endpoints.

### Events sweep — Tags, Teams, Organizations
- **Tags now emit events**: `TagCreated`, `TagUpdated`, `TagDeleted`, `TagsAttached`, `TagsDetached`, `TagsSynced` via `\Innertia\Tags\Events\TagEvent` enum.
- **Teams now emit events**: `TeamCreated`, `TeamUpdated`, `TeamDeleted`, `TeamMembersSynced` via `\Innertia\Platform\Teams\Events\TeamEvent` enum.
- **Organizations now emit events**: `OrganizationCreated`, `OrganizationUpdated`, `OrganizationDeleted` via `\Innertia\Platform\Organizations\Events\OrganizationEvent` enum.
- All 13 events extend `DomainEvent` and integrate with the EventBus — listen via `Innertia::events()->listen(...)`.
- Test with `EventBusFake::fake()` + `assertDispatched(EnumCase, ?callback)`.

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
- `user_contexts` table is unchanged — context-access is orthogonal to organizations.
- Organizations feature is forcibly inactive in `api` mode regardless of
  `organizations.enabled` — API consumers manage their own isolation.
  Centralised via `Innertia\Platform\Organizations\OrganizationsFeature::isActive()`.
