<?php

namespace Innertia\Mail\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Facades\Innertia;
use Innertia\Mail\Branding;
use Innertia\Mail\TenantBranding;
use Innertia\Mail\UseCases\SaveTenantBranding;
use Innertia\Mail\UseCases\SendTestEmail;

/**
 * Brand of the active tenant for business emails (backoffice "Sistema › Marca").
 *
 *   GET  {prefix}        → {branding, resolved, platform}
 *   PUT  {prefix}        → save (display_name, logo_url | logo_file_id, color, footer_address, footer_phone)
 *   POST {prefix}/test   → sends a test email with the tenant brand to the given address (or the user)
 */
class TenantBrandingController extends Controller
{
    public function show(): array
    {
        $tenantId = $this->tenantId();

        return [
            'branding' => TenantBranding::get($tenantId),
            'resolved' => Branding::for($tenantId)->toArray(),
            'platform' => Branding::platform()->toArray(),
        ];
    }

    public function update(Request $request): array
    {
        $data = $request->validate([
            'display_name'   => 'nullable|string|max:120',
            'logo_url'       => 'nullable|url|max:2000',
            'logo_file_id'   => 'nullable|string|max:64',
            'color'          => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'footer_address' => 'nullable|string|max:200',
            'footer_phone'   => 'nullable|string|max:60',
        ], [
            'color.regex'  => 'El color debe ser un hexadecimal como #6366f1 o #fff.',
            'logo_url.url' => 'La URL del logo debe comenzar con http:// o https://.',
        ], [
            'display_name'   => 'nombre visible',
            'footer_address' => 'dirección',
            'footer_phone'   => 'teléfono',
        ]);

        return (new SaveTenantBranding($this->tenantId(), $data))->execute();
    }

    public function test(Request $request): array
    {
        $data = $request->validate(['email' => 'nullable|email']);
        $email = $data['email'] ?? $request->user()?->email;

        abort_if(! $email, 422, 'Ingresa un correo de destino.');

        return (new SendTestEmail($email, $this->tenantId(), platform: false))->execute();
    }

    private function tenantId(): ?string
    {
        $id = Innertia::tenancyEnabled() ? Innertia::tenant()?->getKey() : null;

        return filled($id) ? (string) $id : null;
    }
}
