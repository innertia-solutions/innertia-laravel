<x-innertia::mail.layout
    title="Bienvenido a {{ config('app.name') }}"
    preview="Tu cuenta está lista. ¡Empieza ahora!"
>
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
        ¡Bienvenido, {{ $user->name }}!
    </h1>
    <p style="margin:0 0 24px;font-size:15px;color:#71717a;line-height:1.6;">
        Tu cuenta en <strong>{{ config('app.name') }}</strong> ha sido creada exitosamente.
    </p>

    @if($temporaryPassword)
        <p style="margin:0 0 12px;font-size:15px;color:#3f3f46;font-weight:600;">
            Tus credenciales de acceso:
        </p>

        <x-innertia::mail.table
            :headers="['Campo', 'Valor']"
            :rows="[
                ['Correo electrónico', $user->email],
                ['Contraseña temporal', '<code style=\'font-family:monospace;font-size:14px;\'>' . e($temporaryPassword) . '</code>'],
            ]"
        />

        <x-innertia::mail.panel type="warning">
            Por seguridad, deberás cambiar tu contraseña la primera vez que inicies sesión.
        </x-innertia::mail.panel>
    @else
        <p style="margin:0 0 4px;font-size:15px;color:#71717a;line-height:1.6;">
            Ya puedes iniciar sesión con el correo <strong>{{ $user->email }}</strong>.
        </p>
    @endif

    <x-innertia::mail.button :url="$loginUrl">
        Iniciar sesión
    </x-innertia::mail.button>

    <p style="margin:24px 0 0;font-size:13px;color:#a1a1aa;line-height:1.6;">
        Si no esperabas este mensaje, puedes ignorarlo de forma segura.
    </p>
</x-innertia::mail.layout>
