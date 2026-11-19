@php($brandName = \Innertia\Mail\Branding::current()->name)
<x-innertia::mail.layout
    title="Restablece tu contraseña"
    preview="Recibimos una solicitud para restablecer tu contraseña en {{ $brandName }}."
>
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        Hola{{ $name ? ' '.$name : '' }}
    </h1>
    <p style="margin:0 0 24px;font-size:15px;color:#71717a;line-height:1.6;">
        Recibimos una solicitud para restablecer la contraseña de tu cuenta en
        <strong>{{ $brandName }}</strong>. Para crear una nueva, haz clic en el botón.
    </p>

    <x-innertia::mail.button :url="$resetUrl">
        Restablecer contraseña
    </x-innertia::mail.button>

    <p style="margin:24px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
        <a href="{{ $resetUrl }}" style="color:#a1a1aa;word-break:break-all;">{{ $resetUrl }}</a>
    </p>

    <p style="margin:16px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Este enlace vence en {{ $expiresInMinutes }} minutos. Si no lo solicitaste, puedes ignorar este
        correo: tu contraseña no cambiará.
    </p>
</x-innertia::mail.layout>
