<?php

use Illuminate\Support\Facades\Schema;
use Innertia\Billing\Models\Plan;
use Innertia\Saas\Models\Tenant;

if (! function_exists('bootBillingMode')) {
    /**
     * Clients mode (tenant 'rancagua' + client) + billing feature + billing tables + settings table,
     * and the provider boot again so listeners/notice types/middleware are wired with the feature on.
     */
    function bootBillingMode(\Illuminate\Foundation\Application $app): void
    {
        bootClientsMode($app);
        config()->set('innertia.billing.enabled', true);
        config()->set('innertia.billing.meters', null);
        \Innertia\Billing\Meters::flush();
        \Innertia\Notifications\NoticeTypes::flush();
        \Illuminate\Support\Facades\Cache::flush();

        (require __DIR__ . '/../../../database/migrations/billing/2026_11_19_100001_create_billing_tables.php')->up();
        if (! Schema::hasTable('settings')) {
            (require __DIR__ . '/../../../database/migrations/saas/2025_01_01_000007_create_settings_table.php')->up();
        }

        (new \Innertia\Billing\BillingServiceProvider($app))->boot();
    }
}

if (! function_exists('billingPlan')) {
    function billingPlan(array $attrs = [], array $meters = []): Plan
    {
        return (new \Innertia\Billing\UseCases\SavePlan($attrs + [
            'key' => 'pro-' . uniqid(), 'name' => 'Pro', 'base_price' => 100000, 'included_seats' => 5,
            'seat_price' => 5000, 'tax_rate' => 19, 'meters' => $meters,
        ]))->execute();
    }
}

if (! function_exists('billingTenant')) {
    /** A new production space (with its implicit client) or the given key's tenant. */
    function billingTenant(string $key = 'rancagua', ?string $clientId = null): Tenant
    {
        $tenant = Tenant::findByKey($key);
        if (! $tenant) {
            $tenant = (new \Innertia\Saas\UseCases\CreateTenant(key: $key, name: ucfirst($key), clientId: $clientId))->execute();
        }

        return $tenant->fresh();
    }
}

if (! function_exists('billingSeats')) {
    /** Binds a fixed SeatCounter. */
    function billingSeats(int $count): void
    {
        app()->instance(\Innertia\Billing\Contracts\SeatCounter::class, new class($count) implements \Innertia\Billing\Contracts\SeatCounter {
            public function __construct(private int $n) {}
            public function count(string $tenantId): int { return $this->n; }
        });
    }
}
