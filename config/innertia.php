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
        'db_prefix' => 'tenant_',

        // Domains that host the central (landlord) application
        'central_domains' => [
            'localhost',
            '127.0.0.1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Settings
    |--------------------------------------------------------------------------
    |
    | Controls the built-in Innertia auth layer (JWT + OTP + 2FA).
    |
    */

    'auth' => [
        'otp' => [
            // Send a one-time code via email before issuing the JWT
            'enabled' => false,
            // Minutes the OTP code remains valid
            'ttl' => 10,
        ],

        '2fa' => [
            // Allow users to enrol in TOTP-based two-factor auth
            'enabled' => false,
        ],

        'sessions' => [
            // Invalidate older sessions when a new login occurs from a different device
            'restrict_concurrent' => false,
        ],
    ],

];
