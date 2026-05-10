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

];
