<x-innertia::mail.layout
    title="Verifica tu correo electrónico"
    preview="Confirma tu dirección de correo para continuar."
>
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        Verifica tu correo electrónico
    </h1>
    <p style="margin:0 0 24px;font-size:15px;color:#71717a;line-height:1.6;">
        Haz clic en el botón de abajo para confirmar tu dirección de correo. Este enlace expira en
        <strong>{{ config('innertia.auth.email_verification.ttl', 60) }} minutos</strong>.
    </p>

    <x-innertia::mail.button :url="$url">
        Verificar correo
    </x-innertia::mail.button>

    <x-innertia::mail.panel type="info">
        Si no creaste una cuenta, puedes ignorar este mensaje de forma segura.
    </x-innertia::mail.panel>

    <p style="margin:24px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
        <a href="{{ $url }}" style="color:{{ config('innertia.mail.brand_color', '#6366f1') }};word-break:break-all;">{{ $url }}</a>
    </p>
</x-innertia::mail.layout>
