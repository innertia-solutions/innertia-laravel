@php($brandName = \Innertia\Mail\Branding::current()->name)
<x-innertia::mail.layout
    title="El correo de tu cuenta cambió"
    preview="El correo de tu cuenta en {{ $brandName }} fue cambiado."
>
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        El correo de tu cuenta cambió
    </h1>
    <p style="margin:0 0 24px;font-size:15px;color:#71717a;line-height:1.6;">
        Hola{{ $name ? ' '.$name : '' }}, el correo de tu cuenta en <strong>{{ $brandName }}</strong>
        se cambió a <strong>{{ $newEmail }}</strong> el <strong>{{ $datetime }}</strong>.
        Desde ahora los avisos llegarán a esa dirección.
    </p>

    <x-innertia::mail.panel type="warning">
        ¿No fuiste tú? Contacta al administrador de inmediato.
    </x-innertia::mail.panel>

    <p style="margin:24px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Este es un mensaje automático de seguridad. No respondas a este correo.
    </p>
</x-innertia::mail.layout>
