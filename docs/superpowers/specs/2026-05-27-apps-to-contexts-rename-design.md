# Rename Apps → Contexts

**Fecha:** 2026-05-27
**Estado:** Aprobado para implementación
**Tipo:** Refactor — rename puro, sin cambios de comportamiento
**Scope:** innertia-laravel library

## Problema

El concepto de "apps" (a qué áreas del sistema tiene acceso un usuario) colisiona semánticamente con "apps" en el contexto de frontend (aplicaciones, secciones de front). Renombrar a "contexts" elimina la ambigüedad.

La API en `/auth/me` ya devuelve `contexts` como key JSON — este rename hace que el código interno sea consistente con lo que ya expone la API.

## Alcance

Rename puro. **Cero cambios de comportamiento.** Todo lo que hoy funciona, sigue funcionando igual — solo cambian los nombres.

## Mapa de renombrado

### Tabla + columna
| Antes | Después |
|-------|---------|
| tabla `user_apps` | tabla `user_contexts` |
| columna `app` | columna `context` |

### Clases PHP
| Antes | Después |
|-------|---------|
| `Innertia\Auth\Models\UserApp` | `Innertia\Auth\Models\UserContext` |
| `Innertia\Auth\RBAC\Traits\HasApps` | `Innertia\Auth\RBAC\Traits\HasContexts` |
| `Innertia\Auth\Middleware\AppMiddleware` | `Innertia\Auth\Middleware\ContextMiddleware` |

### Métodos del trait (HasApps → HasContexts)
| Antes | Después |
|-------|---------|
| `hasApp(string $key)` | `hasContext(string $key)` |
| `grantApp(string\|array, ?int)` | `grantContext(string\|array, ?int)` |
| `revokeApp(string, ?int)` | `revokeContext(string, ?int)` |
| `syncApps(array, ?int)` | `syncContexts(array, ?int)` |
| `contextKeys()` | *(ya correcto — se mantiene)* |
| `appKeys()` | `contextKeys()` |
| `appKeysInOrganization(?int)` | `contextKeysInOrganization(?int)` |
| `appCacheKey()` (private) | `contextCacheKey()` (private) |
| `flushAppCache()` (private) | `flushContextCache()` (private) |

### Config
| Antes | Después |
|-------|---------|
| `config('innertia.apps')` | `config('innertia.contexts')` |
| sección `'apps' => [...]` en innertia.php | `'contexts' => [...]` |

### Middleware alias
| Antes | Después |
|-------|---------|
| `app:backoffice` | `context:backoffice` |
| `AppMiddleware` (alias `app`) | `ContextMiddleware` (alias `context`) |

### Cache key prefix (auto-invalidación)
| Antes | Después |
|-------|---------|
| `innertia.apps.{tenant}.{user}` | `innertia.contexts.{tenant}.{user}` |

### Backoffice routes + controller
| Antes | Después |
|-------|---------|
| `GET/POST/DELETE /users/{id}/apps` | `GET/POST/DELETE /users/{id}/contexts` |
| `UsersController::apps()` | `UsersController::contexts()` |
| `UsersController::grantApp()` | `UsersController::grantContext()` |
| `UsersController::revokeApp()` | `UsersController::revokeContext()` |
| `UsersController::syncApps()` | `UsersController::syncContexts()` |

### Login use case + social login
| Antes | Después |
|-------|---------|
| constructor param `$app` | `$context` |
| `$this->app` | `$this->context` |
| `config('innertia.apps')` | `config('innertia.contexts')` |
| `$user->hasApp(...)` | `$user->hasContext(...)` |

### AuthController (limpiar compat parcial)
- Eliminar el bloque legacy que devolvía `$userData['apps']` (línea 53)
- Mantener `'contexts' => $contexts` (línea 93) — ya correcto
- Renombrar `appKeys()` → `contextKeys()` en las llamadas

## Archivos a modificar (35)

### PHP — src/
1. `src/Auth/RBAC/Traits/HasApps.php` → **renombrar** a `HasContexts.php` + editar todo el contenido
2. `src/Auth/Models/UserApp.php` → **renombrar** a `UserContext.php` + editar contenido
3. `src/Auth/Middleware/AppMiddleware.php` → **renombrar** a `ContextMiddleware.php` + editar contenido
4. `src/Auth/Models/User.php` — actualizar use + trait declaration
5. `src/Auth/Http/Controllers/AuthController.php` — renombrar métodos + limpiar legacy `apps` key
6. `src/Auth/UseCases/Login.php` — `$app` → `$context`, `hasApp` → `hasContext`, config key
7. `src/Auth/Social/SocialLogin.php` — `$app` → `$context`, `hasApp` → `hasContext`, config key
8. `src/Auth/Http/Controllers/PasswordController.php` — `app:` → `context:` en constructor args
9. `src/Auth/Http/Controllers/EmailVerificationController.php` — idem
10. `src/Auth/Http/Controllers/OtpController.php` — idem
11. `src/Auth/Http/Controllers/SocialAuthController.php` — idem
12. `src/Backoffice/routes.php` — rutas `/apps` → `/contexts`, params
13. `src/Backoffice/Http/Controllers/UsersController.php` — métodos + validación
14. `src/Saas/Models/Tenant.php` — `use HasApps` → `use HasContexts`
15. `src/Saas/UseCases/CreateTenantAdmin.php` — `config('innertia.apps')` + `grantApp` → `grantContext`
16. `src/Platform/Organizations/Console/OrganizationInstallCommand.php` — `user_apps` → `user_contexts` en la lista de tablas RBAC
17. `src/InnertiaServiceProvider.php` — alias `app` → `context`, clase AppMiddleware → ContextMiddleware

### Config
18. `config/innertia.php` — sección `apps` → `contexts`, comentarios

### Migrations
19. `database/migrations/app/2025_01_01_000001_create_users_tables.php` — tabla + columna
20. `database/migrations/saas/2025_01_01_000001_create_users_tables.php` — tabla + columna

### Stubs
21. `stubs/app/api.private.php` — rutas `/apps` → `/contexts`
22. `stubs/saas/api.private.php` — idem

### Docs + Skills
23. `CLAUDE.md` — todas las referencias
24. `CHANGELOG.md` — añadir entrada de BREAKING CHANGE
25. `README.md` — todas las referencias
26. `docs/organizations.md` — referencias a `user_apps`
27. `src/Skills/innertia-permissions.md` — sección HasApps completa → HasContexts
28. `src/Skills/innertia-config.md` — sección `apps` → `contexts`
29. `src/Skills/innertia-organizations.md` — referencia a `user_apps`

### Tests
30. `tests/Organizations/AppModeWithOrganizationsTest.php` — trait, métodos, schema, config

## Tests de verificación

El test existente `AppModeWithOrganizationsTest.php` cubre `hasContext()` — solo hay que actualizar el nombre del trait y los métodos. No se requieren tests nuevos: el rename no agrega comportamiento.

## BREAKING CHANGE

Este es un breaking change para proyectos consumidores:
- Trait `HasApps` → `HasContexts`
- Métodos `hasApp/grantApp/revokeApp/syncApps/appKeys/appKeysInOrganization`
- Config key `innertia.apps` → `innertia.contexts`
- Tabla DB `user_apps` → `user_contexts`
- Middleware alias `app:xxx` → `context:xxx`
- Backoffice routes `/users/{id}/apps` → `/users/{id}/contexts`

Documentar en CHANGELOG bajo `### BREAKING CHANGES`.

## Out of scope

- Backward compat aliases (no los queremos — estamos en desarrollo)
- Actualizar composable frontend (siguiente tarea separada)
- Eventos de contexto (no existen actualmente, no se agregan)
