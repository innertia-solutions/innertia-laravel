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
    private string $emailSubject = 'Notificación';

    /** @var array<int, array<string, mixed>> */
    private array $blocks = [];

    private function __construct() {}

    public static function make(): static
    {
        return new static();
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
