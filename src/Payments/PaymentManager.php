<?php

namespace Innertia\Payments;

use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Settings;
use Innertia\Payments\Contracts\PaymentGateway;
use Innertia\Payments\Enums\PaymentProvider;
use Innertia\Payments\Providers\FlowProvider;
use Innertia\Payments\Providers\MercadoPagoProvider;
use Innertia\Payments\Providers\TransferProvider;

class PaymentManager
{
    /**
     * Resuelve el gateway de un proveedor. Guard de integridad: en modo split solo un
     * proveedor que soporte split puede procesar. El enablement por plan/tenant es
     * responsabilidad del PRODUCTO (no de la lib).
     */
    public function for(PaymentProvider $provider): PaymentGateway
    {
        $mode = (string) Settings::get('payments.collection_mode', 'direct');
        if ($mode === 'split' && ! $provider->supports('split')) {
            throw new ForbiddenException('Este proveedor no soporta el modo split. Usa MercadoPago.');
        }

        return match ($provider) {
            PaymentProvider::Transfer    => new TransferProvider,
            PaymentProvider::MercadoPago => new MercadoPagoProvider,
            PaymentProvider::Flow        => new FlowProvider,
        };
    }
}
