<?php

namespace Innertia\Mail\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Mail\Branding;
use Innertia\Mail\MailSettings;
use Innertia\Mail\UseCases\SaveMailSettings;
use Innertia\Mail\UseCases\SendTestEmail;

/**
 * Platform mail settings: provider (SMTP/Mailpit, Resend, log), Resend API key (encrypted,
 * never returned in clear), sender and platform brand (logo/color).
 *
 *   GET    mail-settings
 *   PUT    mail-settings
 *   DELETE mail-settings/resend-key
 *   POST   mail-settings/test       {email}  → sends now, returns rendered html for the preview
 *   GET    mail-settings/preview             → rendered sample with the stored brand
 */
class MailSettingsController extends Controller
{
    public function show(): array
    {
        return MailSettings::show();
    }

    public function update(Request $request): array
    {
        $data = $request->validate([
            'mailer'         => 'nullable|in:' . implode(',', MailSettings::MAILERS),
            'resend_api_key' => 'nullable|string|max:200',
            'from_address'   => 'nullable|email',
            'from_name'      => 'nullable|string|max:120',
            'logo_url'       => 'nullable|url',
            'brand_color'    => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        ], [
            'brand_color.regex' => 'El color de marca debe ser un hexadecimal como #6366f1 o #fff.',
            'logo_url.url'      => 'La URL del logo debe comenzar con http:// o https://.',
        ], [
            'from_address' => 'correo del remitente',
            'from_name'    => 'nombre del remitente',
            'logo_url'     => 'URL del logo',
        ]);

        return (new SaveMailSettings($data))->execute();
    }

    public function clearResendKey(): array
    {
        return MailSettings::clearResendKey();
    }

    public function test(Request $request): array
    {
        $data = $request->validate(['email' => 'required|email']);

        return (new SendTestEmail($data['email']))->execute();
    }

    public function preview(): array
    {
        return ['branding' => Branding::platform()->toArray()];
    }
}
