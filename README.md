# innertia-solutions/laravel-kit

The Innertia internal framework. Provides the full platform layer for single-app and SaaS Laravel backends: auth, settings, gates, use cases, realtime events, exceptions, and mail.

## Installation

```bash
composer require innertia-solutions/laravel-kit
```

Auto-discovered by Laravel. Migrations load automatically.

---

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=innertia-config
```

`config/innertia.php` controls the entire framework. The `mode` key is **hardcoded** (not an env var):

```php
return [
    'mode' => 'app',   // 'app' | 'saas'

    'saas' => [
        'tenant_model'    => null,      // defaults to Innertia\Models\Tenant
        'db_strategy'     => 'single',  // 'single' | 'multi'
        'db_prefix'       => 'tenant_',
        'central_domains' => ['localhost', '127.0.0.1'],
    ],

    'auth' => [
        'otp'      => ['enabled' => false, 'ttl' => 10],
        '2fa'      => ['enabled' => false],
        'sessions' => ['restrict_concurrent' => false],
    ],
];
```

---

## Auth

Drop-in JWT auth layer. Register routes from `routes/api.php`:

```php
\Innertia\Auth\AuthManager::routes();
// or with prefix + middleware:
\Innertia\Auth\AuthManager::routes(prefix: 'v1/auth', middleware: ['throttle:10,1']);
```

Routes registered:

| Method | Path | Description |
|--------|------|-------------|
| POST | `/auth/login` | Email + password → JWT (or OTP challenge) |
| POST | `/auth/otp/send` | Send OTP code |
| POST | `/auth/otp/verify` | Verify OTP → JWT (or 2FA challenge) |
| POST | `/auth/2fa/verify` | Verify TOTP code → JWT |
| GET  | `/auth/me` | Authenticated user |
| POST | `/auth/refresh` | Refresh JWT |
| POST | `/auth/logout` | Invalidate token |
| POST | `/auth/2fa/enable` | Enrol in 2FA (returns QR code URL) |
| POST | `/auth/2fa/disable` | Disable 2FA |

Protected routes use the `Innertia\Auth\Middleware\Authenticate` middleware.

Configure `config/auth.php` to use the JWT guard:

```php
'guards' => [
    'api' => ['driver' => 'jwt', 'provider' => 'users'],
],
```

---

## Use Cases

Single class: typed constructor params, typed return, sync or async.

```php
use Innertia\Platform\Contracts\UseCase;

class CreateOrder extends UseCase
{
    public function __construct(
        public readonly string  $customerId,
        public readonly array   $items,
        public readonly ?string $notes = null,
    ) {}

    public function execute(): Order
    {
        return Order::create([
            'customer_id' => $this->customerId,
            'items'       => $this->items,
            'notes'       => $this->notes,
        ]);
    }
}
```

```php
// Sync
$order = (new CreateOrder(customerId: '...', items: [...]))->execute();

// Async — default 'use-cases' queue
(new CreateOrder(customerId: '...', items: [...]))->onQueue();

// Async — specific queue
(new CreateOrder(customerId: '...', items: [...]))->onQueue('critical');

// Async — with delay
(new CreateOrder(customerId: '...', items: [...]))->delay(now()->addMinutes(5));
```

Run the dedicated worker:

```bash
php artisan queue:work --queue=use-cases
```

---

## Gates (Domain Permissions)

One gate class per domain. All abilities defined as methods. Registered explicitly from the domain's service provider — no filesystem scanning.

```php
// app/Domains/Orders/OrdersGate.php
use Innertia\Platform\Contracts\DomainGate;

class OrdersGate extends DomainGate
{
    public function manage(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.manage');
    }

    public function view(User $user): bool
    {
        return true;
    }
}
```

```php
// app/Domains/Orders/OrdersServiceProvider.php
use Innertia\Platform\Support\DomainServiceProvider;

class OrdersServiceProvider extends DomainServiceProvider
{
    public function boot(): void
    {
        $this->registerGate(OrdersGate::class);
        // registers: 'orders.manage', 'orders.view'
    }
}
```

Convention: `OrdersGate::manage` → `'orders.manage'`, `OrdersGate::view` → `'orders.view'`.

Superadmins bypass all gates automatically (requires `isSuperAdmin()` on the User model).

---

## Settings

Global (app mode) or per-tenant with platform fallback (saas mode). Same API in both modes.

```php
use Innertia\Facades\Settings;

Settings::set('invoice.prefix', 'INV-');
Settings::get('invoice.prefix');              // 'INV-'
Settings::get('invoice.prefix', 'DEFAULT');   // with fallback
Settings::getGroup('invoice');                // all keys under 'invoice'
Settings::forget('invoice.prefix');
```

In saas mode, `Settings` resolves to the active tenant automatically and falls back to platform-level settings when the tenant has no value set.

---

## Realtime Events

Extend `RealtimeEvent` to auto-broadcast on dispatch. Implements `ShouldBroadcast`.

```php
use Innertia\Platform\Events\RealtimeEvent;
use Illuminate\Broadcasting\PrivateChannel;

class OrderShipped extends RealtimeEvent
{
    public function __construct(public readonly Order $order) {}

    public function channel(): Channel
    {
        return new PrivateChannel('tenant.' . tenant('id'));
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->order->id, 'status' => $this->order->status];
    }
}

// Dispatches and broadcasts automatically:
OrderShipped::dispatch($order);
```

Default channel: private channel named after the class in kebab-case. Default payload: all public properties via reflection. Override `channel()`, `broadcastAs()`, or `broadcastWith()` as needed.

---

## Exceptions

Register in `bootstrap/app.php`:

```php
use Innertia\Exceptions\InnertiaExceptionHandler;

->withExceptions(function (Exceptions $exceptions) {
    InnertiaExceptionHandler::register($exceptions);
})
```

Consistent JSON responses for all API errors:

```json
{ "message": "Resource not found.", "error": "not_found", "errors": {} }
```

Throw from anywhere:

```php
use Innertia\Exceptions\NotFoundException;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\UnprocessableException;

throw new NotFoundException('Invoice not found.');
throw new ForbiddenException();
throw new ConflictException('Email already in use.');
throw new UnprocessableException('Invalid data.', ['field' => ['error']]);
```

Laravel's `ValidationException`, `AuthenticationException`, `ModelNotFoundException`, and Spatie's `UnauthorizedException` are also handled automatically. In production, unexpected 500 errors never expose internal details.

---

## Mail

Extend `InnertiaMailable`. Define `subject()` and `view()`. All public constructor properties are passed to the view automatically.

```php
use Innertia\Mail\InnertiaMailable;

class WelcomeMail extends InnertiaMailable
{
    public function __construct(public readonly string $name) {}

    public function subject(): string { return 'Welcome to ' . config('app.name'); }

    public function view(): string { return 'emails.welcome'; }
}

Mail::to($user)->send(new WelcomeMail(name: $user->name));
```

Publish and override the built-in mail views (OTP, layout):

```bash
php artisan vendor:publish --tag=innertia-mail-views
```

---

## SaaS / Tenancy

Set `mode = 'saas'` in `config/innertia.php`. Tenancy is configured programmatically — no need to publish `config/tenancy.php`.

Ensure provider order in `bootstrap/providers.php`:

```php
return [
    Innertia\InnertiaServiceProvider::class,   // first
    Stancl\Tenancy\TenancyServiceProvider::class,
    App\Providers\AppServiceProvider::class,
];
```

And in `composer.json`:

```json
"extra": {
    "laravel": {
        "dont-discover": ["innertia-solutions/laravel-kit", "stancl/tenancy"]
    }
}
```

---

## Other Utilities

### DataTable

```php
use Innertia\Facades\DataTable;

$result = DataTable::create('users')
    ->query(User::query())
    ->columns(['id', 'name', 'email', 'created_at'])
    ->searchable(['name', 'email'])
    ->make();
```

### Activity Logger

```php
use Innertia\Facades\ActivityLogger;

ActivityLogger::logUserAction('login');
ActivityLogger::logEntityAction('updated', 'invoice', $invoice->id, 'Changed amount');
ActivityLogger::logSecurityAction('password_changed');
```

### Traits

| Trait | Description |
|-------|-------------|
| `HasNanoId` | Nano IDs instead of auto-increment PKs |
| `HasHistory` | Auto-record entity history on model events |
| `Auditable` | Track `created_by` / `updated_by` |
| `UseEnumWithValues` | Helper methods for PHP 8.1+ backed enums |

---

## Releasing

Use the **Release** GitHub Actions workflow (`workflow_dispatch`). Select `patch`, `minor`, or `major`. The workflow creates and pushes the git tag — Packagist auto-updates via webhook.

## License

MIT
