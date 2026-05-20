<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Mode
    |--------------------------------------------------------------------------
    |
    | 'app'  — single-tenant. Settings are global (tenant_id = null).
    | 'saas' — multi-tenant. Settings resolve per active tenant with fallback
    |          to platform level. Tenancy is configured automatically.
    | 'api'  — API product mode. No users/tenants. Authenticates via client
    |          API keys. Ideal for internal engines: Cognitia, billing, email.
    |
    */

    'mode' => 'app',

    /*
    |--------------------------------------------------------------------------
    | API Product Mode
    |--------------------------------------------------------------------------
    |
    | Only relevant when mode = 'api'.
    |
    | key_prefix  — Prefix for generated client API keys (e.g. 'cog_', 'bil_').
    |               Override per-product to distinguish keys across services.
    | key_header  — HTTP header used to pass the API key in requests.
    |
    | available_permissions — Full list of permissions any client key can hold.
    |   Define them here so Olimpo can display them when creating keys.
    |
    */

    'api' => [
        'key_prefix'  => env('API_KEY_PREFIX', 'api_'),
        'key_header'  => 'X-Api-Key',

        'available_permissions' => [
            // Define your product's permissions here. Example:
            // 'chat.create'      => 'Iniciar conversaciones',
            // 'documents.index'  => 'Indexar documentos',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | TTL in minutes for permission and app-access caches.
    | null = store forever (relies entirely on explicit invalidation).
    |
    | Cache is automatically busted when roles/permissions/apps are changed
    | via the library's traits and use cases.
    |
    */

    'cache' => [
        'ttl' => 60, // minutes — null for no expiry
    ],

    /*
    |--------------------------------------------------------------------------
    | Apps / Contexts
    |--------------------------------------------------------------------------
    |
    | Define the portals or contexts users can log into.
    | Each key is the app identifier sent in the login payload.
    | The value is a human-readable name (used in UIs and error messages).
    |
    | Access is controlled per-user via the user_apps table.
    | Add HasApps to your User model to enable access management:
    |   use Innertia\Traits\HasApps;
    |
    |   $user->grantApp('backoffice')
    |   $user->hasApp('backoffice')    // bool
    |   $user->appKeys()               // ['backoffice', 'sales']
    |
    | Protect routes by app context:
    |   Route::middleware('app:backoffice')->group(...)
    |
    */

    'apps' => [
        'backoffice'  => 'Administración',
        // 'technicians' => 'Portal Técnicos',
        // 'sales'       => 'Portal Ventas',
    ],

    /*
    |--------------------------------------------------------------------------
    | SaaS / Tenancy Settings
    |--------------------------------------------------------------------------
    |
    | Only relevant when mode = 'saas'. Configures stancl/tenancy
    | programmatically — no need to publish config/tenancy.php.
    |
    */

    'saas' => [
        // Eloquent model representing a tenant
        'tenant_model' => null, // defaults to Stancl\Tenancy\Database\Models\Tenant

        // 'single' — all tenants share one database, models use BelongsToTenant + TenantScope
        // 'multi'  — each tenant gets its own database (requires db_prefix)
        'db_strategy' => 'single',

        // Only used when db_strategy = 'multi'. Tenant DB name: {prefix}{tenant_id}
        // 'db_prefix' => 'tenant_',

        // Domains that host the central (landlord) application
        'central_domains' => [
            'localhost',
            '127.0.0.1',
        ],

        // Base domain for client-facing API subdomain resolution.
        // Set this to your API domain so only subdomains of it are resolved as tenants.
        // e.g. 'api.tuproducto.com' → acme.api.tuproducto.com resolves tenant "acme"
        // null → uses first subdomain segment of any host (less safe, fine for dev)
        'api_domain' => env('INNERTIA_API_DOMAIN', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Defaults
    |--------------------------------------------------------------------------
    |
    | These are fallback values only. At runtime the auth layer reads from
    | the Settings system (database) so each app or tenant can override them
    | without a deployment. Set the live values via Settings::set():
    |
    |   Settings::set('auth.otp.enabled', true);
    |   Settings::set('auth.otp.ttl', 10);
    |   Settings::set('auth.2fa.enabled', true);
    |   Settings::set('auth.email_verification.enabled', true);
    |   Settings::set('auth.sessions.restrict_concurrent', true);
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    |
    | Define application permissions. Two formats are supported (mix freely):
    |
    | 1. Classic array format:
    |    ['category' => 'users', 'category_alias' => 'Usuarios', 'permissions' => [
    |        'users.view'   => 'Ver lista de usuarios',
    |        'users.manage' => 'Crear, editar y eliminar usuarios',
    |    ]]
    |
    | 2. Enum class (recommended — type-safe, IDE-complete, carries descriptions):
    |    \App\Enums\UserPermissions::class
    |    Where the enum is a BackedEnum: string. Add an optional description()
    |    method and it will be stored in DB when you run the sync command:
    |
    |      enum UserPermissions: string
    |      {
    |          case View   = 'users.view';
    |          case Manage = 'users.manage';
    |
    |          public function description(): string
    |          {
    |              return match($this) {
    |                  self::View   => 'Ver lista de usuarios',
    |                  self::Manage => 'Crear, editar y eliminar usuarios',
    |              };
    |          }
    |      }
    |
    | The app works WITHOUT running the sync command — permissions are created
    | lazily when first used. The sync command is optional: run it during deploys
    | to keep descriptions in the DB in sync with the code definition:
    |
    |   php artisan innertia:permissions          — create/update descriptions
    |   php artisan innertia:permissions --prune  — also delete removed ones
    |
    | Add HasRoles to your User model to enable role-based checks:
    |   use Innertia\Traits\HasRoles;
    |
    */

    'permissions' => [
        // Classic format example:
        // [
        //     'category'       => 'users',
        //     'category_alias' => 'Usuarios',
        //     'permissions'    => [
        //         'users.view'   => 'Ver lista de usuarios',
        //         'users.manage' => 'Crear, editar y eliminar usuarios',
        //     ],
        // ],

        // Enum format example:
        // \App\Enums\UserPermissions::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Hierarchy (optional)
    |--------------------------------------------------------------------------
    |
    | Declare which permissions implicitly grant others.
    | This is a domain concern — disabled by default.
    |
    | Example: a user with 'users.manage' also has 'users.view'.
    |
    | 'permissions_hierarchy' => [
    |     'users.manage' => ['users.view'],
    | ],
    |
    */

    'permissions_hierarchy' => [],

    /*
    |--------------------------------------------------------------------------
    | Mail / Email Branding
    |--------------------------------------------------------------------------
    |
    | Customise the appearance of transactional emails sent by the package
    | (OTP codes, email verification, etc.).
    |
    | logo_url    — Full URL to your logo image (PNG/SVG recommended, ~40px tall).
    |               If null, the app name is rendered as text instead.
    | brand_color — Primary hex color used for buttons, OTP codes, and accents.
    |               Override via env MAIL_BRAND_COLOR.
    |
    | Apps can also publish and fully override the Blade templates:
    |   php artisan vendor:publish --tag=innertia-mail-views
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    |
    | Storage disk used for tenant data exports (compliance / GDPR).
    | Defaults to the cloud disk (R2/S3). Exports are stored as encrypted ZIPs
    | at exports/{tenant_id}/{year}/{month}/{timestamp}.zip
    |
    */

    'exports' => [
        // Storage disk for export ZIPs (defaults to cloud disk: R2/S3)
        'disk' => env('EXPORT_DISK', env('FILESYSTEM_CLOUD', 'local')),

        // Your TenantExport subclass. When set, Olimpo's POST /olimpo/tenants/{id}/backups
        // will automatically queue an export using this class.
        // Example: \App\Exports\ExportTenantData::class
        'handler' => null,
    ],

    'mail' => [
        'logo_url'    => env('MAIL_LOGO_URL', null),
        'brand_color' => env('MAIL_BRAND_COLOR', '#6366f1'),

        // Path appended to APP_URL to build the login button in emails (e.g. /login)
        'login_path'  => env('MAIL_LOGIN_PATH', '/login'),
    ],

    'auth' => [
        /*
        |----------------------------------------------------------------------
        | User Model
        |----------------------------------------------------------------------
        |
        | The Eloquent model used for authentication. Innertia wires this into
        | auth.providers.users automatically — no need to touch config/auth.php.
        | Override here if your User model lives outside app/Models/User.php.
        |
        */

        'user_model' => \App\Models\User::class,

        'email_verification' => [
            'enabled' => false,
            'ttl'     => 60,           // minutes
        ],

        'otp' => [
            'enabled' => false,
            'ttl'     => 10,        // minutes
        ],

        '2fa' => [
            'enabled' => false,
        ],

        'sessions' => [
            'restrict_concurrent' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backoffice
    |--------------------------------------------------------------------------
    |
    | Built-in admin API for managing users, roles and permissions.
    | All routes are protected by auth by default.
    |
    | prefix     — URL prefix for all backoffice routes.
    |              Override via env BACKOFFICE_PREFIX.
    |
    | middleware — Extra middleware applied on top of auth.
    |              E.g. ['role:admin'] to restrict access to admins only.
    |
    | enabled    — Set to false to disable backoffice routes entirely.
    |              You can then implement your own controllers using the
    |              use cases provided by the library.
    |
    | users.allow_delete — Whether DELETE /backoffice/users/{id} is enabled.
    |                      Defaults to false (soft-delete via flag instead).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    |
    | Client-facing API authentication via X-Api-Key header.
    | Two key types, distinguished by prefix:
    |
    |   inn_t_xxx — Tenant key: resolves tenant only. No user context.
    |               Ideal for ERP integrations, webhooks, server-to-server.
    |
    |   inn_u_xxx — User key: resolves tenant + authenticates a specific user.
    |               Ideal for personal developer access within a tenant.
    |
    | available_permissions defines the maximum set of permissions any key of
    | that type can be granted. Define them matching your app's permission names.
    |
    | Routes go in routes/api.clients.php (published with innertia-routes).
    | Protect them with: Route::middleware('apikey')->group(...)
    |                 or: Route::middleware('apikey:invoices.read')->get(...)
    |
    */

    'api_keys' => [
        'header' => 'X-Api-Key',

        'tenant' => [
            'available_permissions' => [
                // 'invoices.read'  => 'Consultar facturas',
                // 'invoices.write' => 'Crear y actualizar facturas',
                // 'products.read'  => 'Consultar productos',
                // 'products.write' => 'Crear y actualizar productos',
            ],
        ],

        'user' => [
            'available_permissions' => [
                // User keys typically get read-only access:
                // 'invoices.read'  => 'Consultar facturas',
                // 'products.read'  => 'Consultar productos',
            ],
        ],
    ],

    'backoffice' => [
        'prefix'     => env('BACKOFFICE_PREFIX', 'backoffice'),
        'middleware' => [],  // e.g. ['role:admin'] or ['permission:backoffice.access']
        'enabled'    => true,

        'users' => [
            'allow_delete' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | Framework metrics (queries, logs, exceptions, events, requests, datatables)
    | sent from apps to Olimpo for centralized monitoring.
    |
    | mode:
    |   'remote'     — send to Olimpo via HTTP, no local table (default)
    |   'standalone' — store in local DB only, no Olimpo
    |   'both'       — store locally AND send to Olimpo
    |
    | For 'standalone' or 'both': php artisan innertia:telemetry:install
    |
    */

    'telemetry' => [
        'enabled'        => env('TELEMETRY_ENABLED', false),
        'mode'           => env('TELEMETRY_MODE', 'remote'),
        'app_name'       => env('APP_NAME', 'app'),
        'olimpo_url'     => env('OLIMPO_URL'),
        'olimpo_key'     => env('OLIMPO_KEY'),
        'queue'          => env('TELEMETRY_QUEUE', 'telemetry'),
        'timeout'        => 3,
        'retention_days' => env('TELEMETRY_RETENTION_DAYS', 7),

        'capture' => [
            'queries'    => true,
            'logs'       => true,
            'exceptions' => true,
            'datatables' => true,
            'events'     => true,
            'requests'   => true,
        ],

        'except' => [
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Devtools
    |--------------------------------------------------------------------------
    |
    | Remote DB browser and interactive Tinker session accessible from Olimpo.
    | All endpoints are protected by olimpo.auth + devtools.guard.
    |
    | tinker.enabled — set to true to allow remote PHP eval. AUDIT LOGGED.
    |                  Never enable on production without explicit intent.
    | tinker.session_ttl — seconds before an idle Tinker session expires (Redis TTL).
    | tinker.cache_store — explicit cache store (never 'octane' — worker-local).
    |
    */

    'devtools' => [
        'enabled' => env('DEVTOOLS_ENABLED', false),

        'tinker' => [
            'enabled'     => env('DEVTOOLS_TINKER_ENABLED', false),
            'session_ttl' => (int) env('DEVTOOLS_TINKER_SESSION_TTL', 1800), // 30 min
            // Never use 'octane' — it's in-memory and not shared between workers
            'cache_store' => env('DEVTOOLS_TINKER_CACHE_STORE', 'redis'),
        ],
    ],

];
