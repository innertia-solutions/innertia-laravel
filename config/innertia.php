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
    | Only relevant when mode = 'saas'. These values are used to configure
    | stancl/tenancy programmatically — no need to publish config/tenancy.php.
    |
    */

    'saas' => [
        // Eloquent model representing a tenant
        'tenant_model' => null, // defaults to Stancl\Tenancy\Database\Models\Tenant

        // Tenant database name: {prefix}{tenant_id}
        'db_prefix' => 'tenant_',

        // Domains that host the central (landlord) application
        'central_domains' => [
            'localhost',
            '127.0.0.1',
        ],
    ],

];
