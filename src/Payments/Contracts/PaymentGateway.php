<?php

namespace Innertia\Payments\Contracts;

use Innertia\Payments\Models\Charge;
use Innertia\Payments\Models\Payment;

interface PaymentGateway
{
    /** Iniciar el cobro de un charge (link, débito o declaración de transferencia). */
    public function createPayment(Charge $charge): Payment;

    /** Confirmar un pago (webhook para gateways, manual para transferencia). Idempotente. */
    public function confirm(Payment $payment): void;

    /** Procesar el payload de un webhook del proveedor. Devuelve el Charge afectado o null. */
    public function handleWebhook(array $payload): ?Charge;

    /**
     * Valida la firma de un webhook del proveedor. Los args varían por proveedor;
     * se pasan los que el proveedor necesite (el resto se ignora). Devuelve true si
     * la firma es válida o si el proveedor no requiere validación en el entorno actual.
     */
    public function verifySignature(string $signatureHeader = '', string $requestId = '', string $dataId = ''): bool;
}
