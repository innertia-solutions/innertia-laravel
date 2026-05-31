<?php

namespace Innertia\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
 *       public function subjectLine(): string { return 'Welcome!'; }
 *
 *       public function markdownView(): string { return 'emails.welcome'; }
 *   }
 *
 * NOTA: los métodos de contrato se llaman subjectLine() / markdownView() —
 * NO subject() / view() — para no colisionar con los métodos homónimos de
 * Illuminate\Mail\Mailable (que en Laravel 11+ son setters fluidos). Usa la
 * API moderna envelope()/content().
 */
abstract class InnertiaMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Subject line del correo. */
    abstract public function subjectLine(): string;

    /** Vista markdown a renderizar dentro del layout. */
    abstract public function markdownView(): string;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(markdown: $this->markdownView(), with: $this->payload());
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
