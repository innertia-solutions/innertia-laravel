@php
    $titles = [
        'login'              => 'Tu código de verificación',
        'email_verification' => 'Verifica tu correo electrónico',
        'password_reset'     => 'Restablece tu contraseña',
        'sensitive_action'   => 'Confirmación requerida',
    ];
    $descriptions = [
        'login'              => 'Usa el siguiente código para completar tu inicio de sesión.',
        'email_verification' => 'Usa el siguiente código para verificar tu dirección de correo.',
        'password_reset'     => 'Usa el siguiente código para restablecer tu contraseña.',
        'sensitive_action'   => 'Usa el siguiente código para confirmar la acción solicitada.',
    ];
    $title       = $titles[$action]       ?? 'Tu código de acceso';
    $description = $descriptions[$action] ?? 'Usa el siguiente código para completar tu solicitud.';
@endphp
<x-innertia::mail.layout :title="$title" :preview="$description">
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        {{ $title }}
    </h1>
    <p style="margin:0 0 4px;font-size:15px;color:#71717a;line-height:1.6;">
        {{ $description }} Expira en
        <strong>{{ config('innertia.auth.otp.ttl', 10) }} minutos</strong>.
    </p>

    <x-innertia::mail.otp :code="$code" />

    <x-innertia::mail.panel type="warning">
        Si no solicitaste este código, ignora este mensaje. Tu cuenta no ha sido comprometida.
    </x-innertia::mail.panel>
</x-innertia::mail.layout>
