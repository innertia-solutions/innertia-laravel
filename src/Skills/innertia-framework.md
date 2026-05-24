---
name: innertia-framework
description: Use when working on a Laravel backend that uses the `innertia-solutions/laravel-innertia` package. Activates for questions about modes (app/saas/api), DDD structure (Domains/Apps), UseCases, DomainEvents, or general framework conventions. Don't trigger for plain Laravel without this package.
---

# innertia-laravel — framework overview

Este proyecto usa **`innertia-solutions/laravel-innertia`** como capa de plataforma sobre Laravel. La librería NO es un wrapper de stancl/tenancy — implementa su propio manager de tenants.

## Modos de operación

`config('innertia.mode')`:

| Modo | Multitenancy | Auth | Cuándo |
|------|---|---|---|
| `app` | ❌ | ✅ | Single-tenant. App interna. |
| `saas` | ✅ X-Tenant header | ✅ JWT | Multi-tenant. Default para productos. |
| `api` | ❌ | ✅ JWT | API mode. Sin tenancy ni orgs. Consumers manejan su propio scope. |

Importante: en `api` mode, los features Organizations y Teams están **forzosamente disabled** sin importar el flag de config.

## Estructura DDD

```
app/
├── Domains/{Domain}/         # Lógica de negocio
│   ├── Models/               # Eloquent
│   ├── UseCases/             # Lógica (extienden \Innertia\Platform\Contracts\UseCase)
│   ├── Services/             # Helpers reutilizables
│   ├── Events/               # DomainEvents (extienden \Innertia\Platform\Events\DomainEvent)
│   ├── Listeners/
│   ├── Gates/                # Autorización (extienden \Innertia\Platform\Contracts\DomainGate)
│   ├── Enums/
│   ├── Mails/                # extienden \Innertia\Mail\InnertiaMailable
│   └── Observers/
│
└── Apps/{AppName}/{Domain}/Controllers/   # Capa HTTP — delegan a UseCases
```

## Reglas inviolables

- **Controllers solo validan y delegan a UseCases**. Cero lógica de negocio en controllers.
- **Models en `Domains/`, nunca en `app/Models/`**.
- IDs siempre UUID (vía trait `\Innertia\Platform\Traits\HasUuid`) o NanoID (`HasNanoId`).
- En SaaS: modelos tenant-scoped usan `HasTenant` (global scope automático + auto-inject en create).
- UseCases extienden `\Innertia\Platform\Contracts\UseCase` — son `ShouldQueue` (se pueden despachar a queue) y restauran el tenant en background.

## UseCase básico

```php
namespace App\Domains\Orders\UseCases;

use App\Domains\Orders\Models\Order;
use Innertia\Platform\Contracts\UseCase;

class CreateOrder extends UseCase {
    public function __construct(
        public readonly string $customerId,
        public readonly float  $total,
    ) {}

    public function execute(): Order {
        return Order::create([...]);
    }
}

// Sync:
$order = (new CreateOrder($id, $total))->execute();

// Queue:
(new CreateOrder($id, $total))->onQueue();
(new CreateOrder($id, $total))->onQueue('critical');
(new CreateOrder($id, $total))->delay(now()->addMinutes(5));
```

En SaaS el `tenant_key` se captura en `__sleep()` y se restaura en `handle()` — el job ejecuta en el tenant correcto sin importar cuándo corra.

## Comandos artisan más útiles

```bash
# Tenants (SaaS)
php artisan tenant:create {key} {name} [--status=trial|active]
php artisan tenant:list
php artisan tenant:show {key}

# Permisos
php artisan innertia:permissions          # sync de config/innertia.permissions
php artisan innertia:permissions --prune  # también elimina los removidos

# Scaffolding
php artisan innertia:make:model {Domain} {Name}
php artisan innertia:make:usecase {Domain} {Name}
php artisan innertia:make:controller {App} {Domain} {Name}

# Features opt-in
php artisan innertia:organization:install [--force]
php artisan innertia:teams:install [--force]
```

## Skills relacionados

- `innertia-multitenancy` — tenants, HasTenant, X-Tenant header
- `innertia-organizations` — sub-tenant scoping (orgs dentro de un tenant)
- `innertia-teams` — RBAC por grupo de Users
- `innertia-config` — referencia de `config/innertia.php`
- `innertia-extending` — patrón template method para extender UseCases/Controllers
