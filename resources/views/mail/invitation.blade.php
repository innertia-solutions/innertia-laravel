@php($brandName = \Innertia\Mail\Branding::current()->name)
<x-innertia::mail.layout
    title="Bienvenido(a) a {{ $brandName }}"
    preview="Se creó tu cuenta en {{ $brandName }}. Activa tu cuenta y crea tu contraseña."
>
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        ¡Hola{{ $name ? ', '.$name : '' }}!
    </h1>
    <p style="margin:0 0 24px;font-size:15px;color:#71717a;line-height:1.6;">
        Se creó tu cuenta en <strong>{{ $brandName }}</strong>. Para empezar,
        activa tu cuenta y crea tu contraseña haciendo clic en el botón de abajo.
    </p>

    <x-innertia::mail.button :url="$activationUrl">
        Activar cuenta y crear contraseña
    </x-innertia::mail.button>

    <p style="margin:24px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
        <a href="{{ $activationUrl }}" style="color:#a1a1aa;word-break:break-all;">{{ $activationUrl }}</a>
    </p>

    <p style="margin:16px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Este enlace es de un solo uso y vence en {{ $expiresInDays }} {{ $expiresInDays === 1 ? 'día' : 'días' }}.
        Si vence, pide a quien te invitó que te reenvíe la invitación.
    </p>

    <p style="margin:16px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Si no esperabas este mensaje, puedes ignorarlo de forma segura.
    </p>
</x-innertia::mail.layout>
