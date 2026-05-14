<?php

namespace Innertia\Platform\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Innertia\Facades\Innertia;

abstract class UseCase implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public string $queue   = 'use-cases';
    public int    $tries   = 3;
    public int    $timeout = 60;

    /**
     * Clave del tenant activo al momento de despachar a la cola.
     * Capturado en __sleep() (serialización), restaurado en handle().
     * En ejecución sincrónica (execute() directo) no se usa — el contexto ya está activo.
     */
    protected ?string $__tenantKey = null;

    abstract public function execute();

    /**
     * Llamado por el queue worker. Restaura el tenant antes de ejecutar.
     */
    public function handle(): void
    {
        if ($this->__tenantKey) {
            Innertia::activate($this->__tenantKey);
        }

        try {
            $this->execute();
        } finally {
            if ($this->__tenantKey) {
                Innertia::deactivate();
            }
        }
    }

    /**
     * Dispatch a una cola.
     *
     *   (new CreateOrder(...))->onQueue();            // → 'use-cases'
     *   (new CreateOrder(...))->onQueue('critical');  // → 'critical'
     */
    public function onQueue(?string $queue = null): void
    {
        $this->__tenantKey = Innertia::tenant()?->key;
        $this->queue       = $queue ?? 'use-cases';

        dispatch($this);
    }

    /**
     * Dispatch con delay.
     *
     *   (new CreateOrder(...))->delay(now()->addMinutes(5));
     *   (new CreateOrder(...))->delay(30); // seconds
     */
    public function delay(\DateTimeInterface|\DateInterval|int $delay): void
    {
        $this->__tenantKey = Innertia::tenant()?->key;

        dispatch($this)->delay($delay);
    }
}
