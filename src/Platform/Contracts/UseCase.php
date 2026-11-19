<?php

namespace Innertia\Platform\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Innertia\Facades\Innertia;

// Dynamic properties allowed: delay() stores the queue delay in `$this->delay` (read by the bus dispatcher)
// without declaring it here, so subclasses that already declare `$delay` (typed or via Queueable) keep working.
#[\AllowDynamicProperties]
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
        if (! $this->__tenantKey) {
            $this->execute();

            return;
        }

        // Remember the tenant that was active before (sync queue / nested dispatch) and restore it afterwards,
        // instead of leaving the context empty.
        $previous = Innertia::tenant();
        Innertia::activate($this->__tenantKey);

        try {
            $this->execute();
        } finally {
            if ($previous) {
                app(\Innertia\Saas\TenantContext::class)->set($previous);
            } else {
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

        // Do NOT call PendingDispatch::delay(): it calls $job->delay() back (infinite recursion).
        // The bus dispatcher reads the `delay` property of the job.
        $this->delay = $delay;

        dispatch($this);
    }
}
