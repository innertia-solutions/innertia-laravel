<?php

namespace Innertia\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Innertia\Facades\Innertia;
use Throwable;

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
 *
 * Branding (opt-in, backwards compatible): override mailBranding() to render the email
 * with a resolved Branding (Branding::platform() for account emails, Branding::for($tenant)
 * for business emails). By default it returns null and the layout keeps using
 * config('innertia.mail.*') exactly as before.
 *
 * Email log: every InnertiaMailable carries X-Metadata-innertia_type (emailType()) and
 * X-Metadata-innertia_tenant, so \Innertia\Mail\EmailLogging records type and tenant of
 * every email (account, OTP, business) instead of "unknown".
 */
abstract class InnertiaMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Tenant captured when the mail is queued/sent (restored for branding and the log). */
    protected ?string $innertiaTenantId = null;

    /** Brand used to render this email. null = legacy config brand (Branding::current()). */
    public function mailBranding(): ?Branding
    {
        return null;
    }

    /** Type recorded in email_logs (e.g. 'account.invitation'). Defaults to the class name in kebab case. */
    public function emailType(): ?string
    {
        return null;
    }

    /** Pins the tenant whose brand/log this email belongs to (default: active tenant). */
    public function forTenant(mixed $tenant): static
    {
        if (is_object($tenant)) {
            $tenant = method_exists($tenant, 'getKey') ? $tenant->getKey() : ($tenant->id ?? null);
        }

        $this->innertiaTenantId = filled($tenant) ? (string) $tenant : null;

        return $this;
    }

    public function mailTenantId(): ?string
    {
        return $this->innertiaTenantId;
    }

    public function send($mailer)
    {
        $this->captureTenant();
        $this->tagForLog();

        return Branding::using($this->safeBranding(), fn () => parent::send($mailer));
    }

    public function render()
    {
        $this->captureTenant();

        return Branding::using($this->safeBranding(), fn () => parent::render());
    }

    public function queue(QueueFactory $queue)
    {
        $this->captureTenant();

        return parent::queue($queue);
    }

    public function later($delay, QueueFactory $queue)
    {
        $this->captureTenant();

        return parent::later($delay, $queue);
    }

    /** Resolved type used by the email log. */
    public function resolvedEmailType(): string
    {
        return $this->emailType() ?? Str::kebab(class_basename(static::class));
    }

    private function captureTenant(): void
    {
        if ($this->innertiaTenantId !== null) {
            return;
        }

        try {
            $id = Innertia::tenancyEnabled() ? Innertia::tenant()?->getKey() : null;
            $this->innertiaTenantId = filled($id) ? (string) $id : null;
        } catch (Throwable) {
            // No tenant context: leave null.
        }
    }

    private function tagForLog(): void
    {
        $this->metadata('innertia_type', $this->resolvedEmailType());

        if ($this->innertiaTenantId !== null) {
            $this->metadata('innertia_tenant', $this->innertiaTenantId);
        }
    }

    /** A branding failure must never block an email: fall back to the legacy brand. */
    private function safeBranding(): ?Branding
    {
        try {
            return $this->mailBranding();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

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
