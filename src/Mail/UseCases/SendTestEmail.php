<?php

namespace Innertia\Mail\UseCases;

use Illuminate\Support\Facades\Mail;
use Innertia\Mail\Branding;
use Innertia\Mail\EmailLogging;
use Innertia\Mail\MailSettings;
use Innertia\Mail\NotificationMail;
use Innertia\Platform\Contracts\UseCase;

/**
 * Sends a test email right away (not queued: the admin wants the provider's answer) with the
 * stored provider and the given brand (platform brand by default, tenant brand for the
 * "Marca" page). Returns the rendered HTML too, for the preview.
 */
class SendTestEmail extends UseCase
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $tenantId = null,
        public readonly bool $platform = true,
    ) {}

    public function execute(): array
    {
        $mailer = MailSettings::apply();
        $branding = $this->platform ? Branding::platform() : Branding::for($this->tenantId);

        $mail = NotificationMail::make()
            ->type('mail.test')
            ->withBranding($branding)
            ->withSubject('Correo de prueba — ' . $branding->name)
            ->title('¡La configuración de correo funciona!')
            ->line('Este es un correo de prueba enviado desde ' . e($branding->name) . '.')
            ->line('Transporte usado: <strong>' . e($mailer) . '</strong>.')
            ->panel('Si lo recibiste, el envío y la marca están correctos.', 'success');

        if ($this->tenantId !== null) {
            $mail->forTenant($this->tenantId);
        }

        $html = $mail->render();
        EmailLogging::queued($mail, $this->email, type: 'mail.test', tenantId: $this->tenantId);

        Mail::mailer($mailer)->to($this->email)->send($mail);

        return ['ok' => true, 'sent_to' => $this->email, 'mailer' => $mailer, 'html' => $html];
    }
}
