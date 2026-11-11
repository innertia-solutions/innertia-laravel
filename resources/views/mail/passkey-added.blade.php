<x-innertia::mail.layout
    title="Nueva passkey"
    preview="Agregaste una passkey para ingresar sin contraseña."
>
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        Agregaste una passkey
    </h1>
    <p style="margin:0 0 24px;font-size:15px;color:#71717a;line-height:1.6;">
        Hola <strong>{{ $user->name }}</strong>, el <strong>{{ $datetime }}</strong> agregaste la passkey
        <strong>{{ $passkeyName }}</strong> a tu cuenta en <strong>{{ config('app.name') }}</strong>.
        Desde ahora puedes ingresar con tu huella, rostro o PIN del dispositivo.
    </p>

    <x-innertia::mail.panel type="warning">
        ¿No fuiste tú? Entra a tu perfil, elimina la passkey y cambia tu contraseña, o avisa al administrador.
    </x-innertia::mail.panel>

    <p style="margin:24px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Este es un mensaje automático de seguridad. No respondas a este correo.
    </p>
</x-innertia::mail.layout>
