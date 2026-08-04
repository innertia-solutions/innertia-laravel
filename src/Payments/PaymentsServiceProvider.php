<?php

namespace Innertia\Payments;

use Illuminate\Support\ServiceProvider;

class PaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Opt-in (mismo patrón que Organizations/Teams/Tags/Directories): el motor de
        // cobros a usuarios finales solo carga sus migraciones (tablas charges/payments)
        // cuando el producto lo habilita en config('innertia.payments.enabled').
        // Default OFF → no impone tablas a productos que no operan pagos reales, evitando
        // colisiones de nombre con el dominio propio del producto.
        if (config('innertia.payments.enabled', false)) {
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/payments');
        }
    }
}
