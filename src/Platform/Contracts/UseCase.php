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
     * Captura el key del tenant activo en el momento de construcción del UseCase.
     * Al ejecutarse en queue, se restaura el contexto antes de llamar execute().
     */
    protected ?string $__tenantKey = null;

    public function __construct()
    {
        // Capturar el tenant activo al momento de crear el UseCase.
        // Innertia::tenant() devuelve null en App mode — sin efecto.
        $this->__tenantKey = Innertia::tenant()?->key;
    }

    abstract public function execute(): mixed;

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
        $this->queue = $queue ?? 'use-cases';

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
        dispatch($this)->delay($delay);
    }
}
