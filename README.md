# innertia-solutions/laravel-innertia

The Innertia internal framework. Provides the full platform layer for single-app and SaaS Laravel backends: auth, settings, gates, use cases, realtime events, exceptions, and mail.

## Installation

```bash
composer require innertia-solutions/laravel-innertia
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
    'mode' => 'app',   // 'app' | 'saas' | 'api'

    'saas' => [
        'tenant_model'    => null,      // defaults to Innertia\Models\Tenant
        'db_strategy'     => 'single',  // 'single' | 'multi'
        'db_prefix'       => 'tenant_',
        'central_domains' => ['localhost', '127.0.0.1'],
    ],

    'auth' => [
        'email_verification' => ['enabled' => false],
        'otp'                => ['enabled' => false, 'ttl' => 10],
        '2fa'                => ['enabled' => false],
        'sessions'           => ['restrict_concurrent' => false],
    ],
];
```

---

## Organizations (opt-in)

Optional **second-level scoping** layer that sits on top of (or in place of) the Tenant layer. Enable it when you need to isolate data between business units, departments, client orgs, or subsidiaries — without forcing the full multi-tenant model on every request. Off by default; apps that don't opt in behave byte-identical to pre-0.3.0.

| Mode | Organizations support |
|---|---|
| `app` | ✅ Works — single-layer scoping |
| `saas` | ✅ Works — second-layer scoping inside tenant |
| `api` | ❌ Forcibly inactive — API consumers manage isolation |

**Activation checklist:**

1. Set `organizations.enabled => true` in `config/innertia.php`.
2. List your domain tables under `organizations.tables`.
3. Run `php artisan innertia:organization:install` then `php artisan migrate`.
4. Add the `HasOrganization` trait to scoped models.
5. Add `organization.resolve` + `organization.require` middleware to protected routes.
6. Clients send the `X-Organization: <slug>` header per request.

For the full guide, see [docs/organizations.md](docs/organizations.md).

---

## Auth

Drop-in JWT auth layer. Register routes from `routes/api.php`:

```php
\Innertia\Auth\AuthManager::routes();
// or with prefix + middleware:
\Innertia\Auth\AuthManager::routes(prefix: 'v1/auth', middleware: ['throttle:10,1']);
```

Configure `config/auth.php` to use the JWT guard:

```php
'guards' => [
    'api' => ['driver' => 'jwt', 'provider' => 'users'],
],
```

Protected routes use the `Innertia\Auth\Middleware\Authenticate` middleware.

### Auth settings are stored in the database

All auth feature flags are read from the **Settings system** at runtime, not from `config/innertia.php`. This means each app (or each tenant in saas mode) can have its own auth configuration without a deployment.

Set them via `Settings::set()` — typically from an admin panel or via the Olimpo API:

```php
Settings::set('auth.email_verification.enabled', true);
Settings::set('auth.otp.enabled', true);
Settings::set('auth.otp.ttl', 10);           // minutes
Settings::set('auth.2fa.enabled', true);
Settings::set('auth.sessions.restrict_concurrent', true);
```

The values in `config/innertia.php` under `auth` are fallback defaults used only when no DB value has been set.

### Sessions

Every successful login is recorded in the `user_sessions` table with `user_id`, `tenant_id` (saas only), `token_hash`, `device_id` (from `X-Device-Id` header), `ip`, `browser`, and `expires_at`. With `sessions.restrict_concurrent = true`, older sessions from other devices are invalidated on each new login.

### Routes

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/login` | — | Credentials → token or challenge |
| POST | `/auth/otp/send` | — | Send OTP to user's email |
| POST | `/auth/otp/verify` | — | Verify OTP → token or next challenge |
| POST | `/auth/2fa/verify` | — | Verify TOTP code → token |
| POST | `/auth/email/verify/send` | — | Send email verification OTP |
| POST | `/auth/email/verify` | — | Verify email OTP → token or next challenge |
| POST | `/auth/password/change` | — | Change password after force_password_change (post-OTP) |
| POST | `/auth/password/set` | — | Set password from invitation flow (post-OTP) |
| GET  | `/auth/me` | ✓ | Authenticated user |
| POST | `/auth/refresh` | ✓ | Refresh JWT |
| POST | `/auth/logout` | ✓ | Invalidate token + session |
| POST | `/auth/2fa/enable` | ✓ | Enrol in 2FA (returns QR code URL) |
| POST | `/auth/2fa/disable` | ✓ | Disable 2FA |

### Login check order

On every login attempt the following checks run in strict order. The first match returns a challenge instead of a token:

```
1. Invalid credentials              → 422

2. force_password_change = true     → OTP always sent (proves email ownership)
                                    → { requires_password_change: true, user_id }

3. email_verified_at = null
   + email_verification.enabled     → { requires_email_verification: true, user_id }

4. otp.enabled                      → OTP sent
                                    → { requires_otp: true, user_id }

5. user.two_factor_enabled = true   → { requires_2fa: true, user_id }

6.                                  → { token, user }
```

`force_password_change` OTP implicitly covers email verification — completing the password change marks `email_verified_at` as well, so a second OTP is never sent.

### Flow A — Standard login

```
POST /auth/login  { email, password, app }
→ { token, user }
```

### Flow B — OTP enabled

```
POST /auth/login  { email, password, app }
→ { requires_otp: true, user_id }

POST /auth/otp/verify  { user_id, code, action: "login", app }
→ { token, user }
  | { requires_2fa: true, user_id }      ← if user has 2FA enrolled

POST /auth/2fa/verify  { user_id, code }
→ { token, user }
```

### Flow C — force_password_change (admin set a temporary password)

OTP is **always** sent on login regardless of `otp.enabled`. Completing the flow also marks the email as verified.

```
POST /auth/login  { email, password, app }
→ OTP sent automatically
→ { requires_password_change: true, user_id }

POST /auth/otp/verify  { user_id, code, action: "login", app }
→ { requires_password_change: true, user_id }   ← force_password_change still active

POST /auth/password/change  { user_id, password, password_confirmation, app }
→ clears force_password_change, marks email_verified_at
→ { token, user }
  | { requires_2fa: true, user_id }

POST /auth/2fa/verify  { user_id, code }
→ { token, user }
```

### Flow D — Email verification enabled, no password on user creation (invitation)

Used when `email_verification.enabled = true` and the user is created without a password (invitation flow).

```
POST /auth/email/verify/send  { user_id }
→ signed email link sent (action: email_verification)

GET /auth/email/verify?user_id=...&signature=...&app=...
→ marks email_verified_at
→ { token, user }
  | { requires_otp: true, user_id }   ← if otp.enabled
  | { requires_2fa: true, user_id }   ← if 2fa.enabled

── Invitation (user has no password yet) ──

POST /auth/otp/verify  { user_id, code, action: "login", app }
→ { requires_password_set: true, user_id }   ← user has no password

POST /auth/password/set  { user_id, password, password_confirmation, app }
→ marks email_verified_at
→ { token, user }
  | { requires_2fa: true, user_id }

POST /auth/2fa/verify  { user_id, code }
→ { token, user }
```

### Flow E — force_password_change + email_verification enabled

Collapses into Flow C — the OTP from `force_password_change` covers email verification. No second OTP is sent.

### Flow F — All features active (email verified, OTP + 2FA enabled)

```
POST /auth/login  { email, password, app }
→ { requires_otp: true, user_id }

POST /auth/otp/verify  { user_id, code, action: "login", app }
→ { requires_2fa: true, user_id }

POST /auth/2fa/verify  { user_id, code }
→ { token, user }
```

### User flags

| Column | Type | Description |
|--------|------|-------------|
| `email_verified_at` | timestamp nullable | Set when email is verified |
| `force_password_change` | boolean | Forces password change + OTP on next login |
| `two_factor_secret` | text nullable | Encrypted TOTP secret |
| `two_factor_enabled` | boolean | Whether 2FA is active for this user |

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
        "dont-discover": ["innertia-solutions/laravel-innertia", "stancl/tenancy"]
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
