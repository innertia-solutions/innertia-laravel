<x-innertia::mail.layout
    title="Contraseña actualizada"
    preview="Tu contraseña fue cambiada exitosamente."
>
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        Contraseña actualizada
    </h1>
    <p style="margin:0 0 24px;font-size:15px;color:#71717a;line-height:1.6;">
        Hola <strong>{{ $user->name }}</strong>, tu contraseña en
        <strong>{{ config('app.name') }}</strong> fue cambiada exitosamente
        el <strong>{{ $datetime }}</strong>.
    </p>

    <x-innertia::mail.panel type="warning">
        ¿No fuiste tú? Contacta al administrador de inmediato o cambia tu contraseña cuanto antes.
    </x-innertia::mail.panel>

    <p style="margin:24px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Este es un mensaje automático de seguridad. No respondas a este correo.
    </p>
</x-innertia::mail.layout>
