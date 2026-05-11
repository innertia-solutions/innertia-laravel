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

    'auth' => [
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
