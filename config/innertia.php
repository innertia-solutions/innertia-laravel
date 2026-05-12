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
    |
    */

    'mode' => 'app',

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
    | Define your application permissions grouped by category. Run:
    |   php artisan innertia:permissions          — create missing permissions
    |   php artisan innertia:permissions --prune  — also delete removed ones
    |
    | Each permission key is the Spatie permission name (e.g. 'users.view').
    | The value is a human-readable description shown in role management UIs.
    |
    */

    'permissions' => [
        // [
        //     'category'       => 'users',
        //     'category_alias' => 'Usuarios',
        //     'permissions'    => [
        //         'users.view'   => 'Ver lista de usuarios',
        //         'users.manage' => 'Crear, editar y eliminar usuarios',
        //     ],
        // ],
    ],

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
        'disk' => env('EXPORT_DISK', env('FILESYSTEM_CLOUD', 'local')),
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

];
