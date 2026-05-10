<?php

namespace Innertia\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Base mailable for all Innertia package emails.
 * Uses the innertia::mail.layout blade component.
 *
 * Apps can publish and override the layout:
 *   php artisan vendor:publish --tag=innertia-mail-views
 *
 * Usage:
 *   class WelcomeMail extends InnertiaMailable
 *   {
 *       public function __construct(public readonly string $name) {}
 *
 *       public function subject(): string { return 'Welcome!'; }
 *
 *       public function view(): string { return 'emails.welcome'; }
 *   }
 */
abstract class InnertiaMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    abstract public function subject(): string;

    /** Blade view to render inside the layout. */
    abstract public function view(): string;

    public function build(): static
    {
        return $this
            ->subject($this->subject())
            ->markdown($this->view(), $this->payload());
    }

    /**
     * Data passed to the view. Defaults to all public properties.
     * Override to customise what the template receives.
     */
    protected function payload(): array
    {
        return array_filter(
            get_object_vars($this),
            fn ($key) => ! in_array($key, ['connection', 'queue', 'chainQueue', 'delay', 'chained', 'chainCatchCallbacks'], true),
            ARRAY_FILTER_USE_KEY
        );
    }
}
