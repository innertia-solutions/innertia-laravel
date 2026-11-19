@php($brandName = \Innertia\Mail\Branding::current()->name)
<x-innertia::mail.layout
    title="Confirma tu nuevo correo"
    preview="Tu código para confirmar el cambio de correo es {{ $code }}."
>
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        Confirma tu nuevo correo
    </h1>
    <p style="margin:0 0 8px;font-size:15px;color:#71717a;line-height:1.6;">
        Hola{{ $name ? ' '.$name : '' }}, pediste usar esta dirección en tu cuenta de <strong>{{ $brandName }}</strong>.
        Ingresa este código para confirmar el cambio:
    </p>

    <x-innertia::mail.otp :code="$code" />

    <p style="margin:16px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        El código vence en {{ $expiresInMinutes }} minutos. Si no fuiste tú, ignora este correo: tu cuenta no cambiará.
    </p>
</x-innertia::mail.layout>
