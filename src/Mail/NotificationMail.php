<?php

namespace Innertia\Mail;

/**
 * Generic fluent mailable for DomainEvent notifications and one-off emails.
 *
 * Usage in DomainEvent::toMail():
 *
 *   return NotificationMail::make()
 *       ->withSubject('Tu pedido fue enviado')
 *       ->title('¡Pedido en camino!')
 *       ->line('Tu pedido #123 fue enviado el 12/05/2026.')
 *       ->table(['Campo', 'Valor'], [['Pedido', '#123'], ['Estado', 'Enviado']])
 *       ->action('Ver pedido', 'https://app.com/orders/123')
 *       ->panel('Entrega estimada en 2-3 días.', 'info');
 *
 * Usage standalone:
 *
 *   Mail::to($user)->queue(
 *       NotificationMail::make()
 *           ->withSubject('Nuevo comentario')
 *           ->title('Tienes un nuevo comentario')
 *           ->line("$actor comentó en $resource.")
 *           ->action('Ver comentario', $url)
 *   );
 */
class NotificationMail extends InnertiaMailable
{
    /** Queue retries (null = worker default). The Notifier sets 3 with backoff. */
    public ?int $tries = null;

    /** Seconds between retries (int|int[]|null). */
    public $backoff = null;

    private string $emailSubject = 'Notificación';

    /** Notice type recorded in email_logs (null = 'notification'). */
    private ?string $noticeType = null;

    /** Explicit brand (null = tenant brand with platform fallback). */
    private ?array $brandingOverride = null;

    /** @var array<int, array<string, mixed>> */
    private array $blocks = [];

    private function __construct() {}

    public static function make(): static
    {
        return new static();
    }

    // ── Delivery options ──────────────────────────────────────────────────────

    /** Type recorded in email_logs (e.g. 'voicemail.new'). */
    public function type(string $type): static
    {
        $this->noticeType = $type;
        return $this;
    }

    /** Forces a brand (e.g. Branding::platform() for platform-team alerts). */
    public function withBranding(Branding $branding): static
    {
        $this->brandingOverride = $branding->toArray();
        return $this;
    }

    /** Queue retries + backoff for the delivery job. */
    public function retries(int $tries, int|array $backoff = 60): static
    {
        $this->tries = $tries;
        $this->backoff = $backoff;
        return $this;
    }

    public function emailType(): ?string
    {
        return $this->noticeType ?? 'notification';
    }

    /**
     * Business emails carry the brand of the tenant they belong to (captured when queued),
     * falling back to the platform brand. Without tenant/branding settings this resolves to
     * the same values the legacy layout used.
     */
    public function mailBranding(): ?Branding
    {
        if ($this->brandingOverride !== null) {
            $b = $this->brandingOverride;

            return new Branding(
                name: (string) $b['name'],
                logoUrl: $b['logo_url'],
                color: (string) $b['color'],
                footerAddress: $b['footer_address'],
                footerPhone: $b['footer_phone'],
                via: $b['via'],
            );
        }

        return Branding::for($this->mailTenantId());
    }

    // ── Fluent block builders ─────────────────────────────────────────────────

    /** Set the email subject line. */
    public function withSubject(string $subject): static
    {
        $this->emailSubject = $subject;
        return $this;
    }

    /** Large heading at the top of the email body. */
    public function title(string $text): static
    {
        $this->blocks[] = ['type' => 'title', 'text' => $text];
        return $this;
    }

    /** Regular paragraph line. May contain basic HTML (escaped via {!! !!} in view). */
    public function line(string $text): static
    {
        $this->blocks[] = ['type' => 'line', 'text' => $text];
        return $this;
    }

    /** Call-to-action button using the brand color. */
    public function action(string $label, string $url): static
    {
        $this->blocks[] = ['type' => 'button', 'label' => $label, 'url' => $url];
        return $this;
    }

    /**
     * Data table.
     *
     * @param string[] $headers  Column header labels.
     * @param array[]  $rows     Each row is an array of cell values (strings / HTML).
     */
    public function table(array $headers, array $rows): static
    {
        $this->blocks[] = ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
        return $this;
    }

    /**
     * Highlighted info/success/warning/danger panel.
     *
     * @param string $type  info | success | warning | danger
     */
    public function panel(string $text, string $type = 'info'): static
    {
        $this->blocks[] = ['type' => 'panel', 'text' => $text, 'panelType' => $type];
        return $this;
    }

    // ── InnertiaMailable contract ─────────────────────────────────────────────

    public function subjectLine(): string
    {
        return $this->emailSubject;
    }

    public function markdownView(): string
    {
        return 'innertia::mail.notification';
    }

    protected function payload(): array
    {
        $preview = collect($this->blocks)
            ->firstWhere('type', 'line');

        return [
            'blocks'  => $this->blocks,
            'preview' => $preview ? strip_tags($preview['text']) : null,
        ];
    }
}
