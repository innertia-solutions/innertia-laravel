# Correos y notificaciones con marca

Motor reutilizable de avisos (campanita + correo), registro de correos enviados, configuración de
correo de plataforma, marca por espacio (tenant), invitaciones, recuperación de contraseña y
cambio de correo.

**Todo es opt-in y aditivo.** Un producto que no llama nada de esto sigue enviando los mismos
correos que antes: el layout sin `$branding` usa `config('innertia.mail.*')` y `app.name` igual
que siempre. Las migraciones nuevas son idempotentes y solo agregan tablas/columnas.

## 1. Migraciones

`php artisan migrate` (se cargan solas en modos app/saas/open):

| Tabla | Para qué |
|---|---|
| `notification_preferences` | usuario × tipo × canal (`bell`/`mail`) → activo |
| `notice_states` | aviso abierto por (tenant, tipo, objeto, usuario): antirrebote, agrupación y `resolve()` |
| `invitation_tokens` | links de invitación (broker propio, 7 días) |
| `email_logs` (+`type`, `tenant_id`, `trace_id`) | registro de todo correo |

## 2. Arranque (provider del producto)

```php
public function boot(): void
{
    // Configuración de correo de /platform (proveedor, remitente, logo, color):
    // se aplica al boot y antes de cada job de la cola.
    \Innertia\Mail\MailSettings::boot();

    // Registro de enviados/fallidos en email_logs (reemplaza LogSentEmail/MarkFailedEmail propios).
    \Innertia\Mail\EmailLogging::listen();

    // Recuperación de contraseña con marca y link al front (innertia.auth.frontend_reset_path).
    \Innertia\Auth\Passwords\ResetPasswordLinks::register();

    // Tipos de aviso del producto.
    \Innertia\Notifications\NoticeTypes::register(
        new NoticeType('voicemail.new', 'Mensaje de voz nuevo', 'Cuando dejan un mensaje en tu buzón.'),
        new NoticeType('call.missed', 'Llamada perdida', channels: ['bell'], group: true,
                       groupTitle: ':count llamadas perdidas'),
        new NoticeType('central.offline', 'Central sin conexión', debounceMinutes: 30),
    );
}
```

> No registres a la vez los listeners de la lib y las copias propias (`App\Listeners\LogSentEmail`):
> duplicarías filas. Borra las copias al adoptar.

## 3. Rutas

Ninguna ruta trae auth propia: regístralas **dentro** de los grupos autenticados del producto.

```php
// Grupo platform (guard de plataforma)
\Innertia\Mail\Routes::platform('');                    // GET emails, GET emails/types (owner y staff)
                                                       // mail-settings* solo owner (middleware platform.owner)
\Innertia\Notifications\Routes::register('notifications');  // campanita de platform
\Innertia\Notifications\Routes::preferences();          // GET|PUT me/notification-preferences

// Grupo backoffice (tenant)
\Innertia\Notifications\Routes::register();             // campanita
\Innertia\Notifications\Routes::preferences();          // preferencias
\Innertia\Auth\EmailChange\Routes::account();           // POST me/email-change, POST me/email-change/confirm
Route::middleware('permission:settings.manage')
    ->group(fn () => \Innertia\Mail\Routes::tenantBranding('settings/branding'));  // GET|PUT, POST /test
Route::middleware('permission:users.manage')
    ->group(fn () => \Innertia\Auth\Invitations\Routes::admin('users'));           // GET|POST users/{id}/invitation
```

`\Innertia\Backoffice\Routes::register()` ya incluye `users/{id}/invitation` y expone
`invited_at` / `invitation_expires_at` en `users` (listado y detalle).

| Endpoint | Respuesta |
|---|---|
| `GET emails?status=&type=&tenant_id=&search=&from=&to=` | paginado de `email_logs` + `tenant_name` |
| `GET mail-settings` / `PUT` / `DELETE mail-settings/resend-key` | proveedor, remitente, logo, color (`resend_api_key_set`, nunca la key) |
| `POST mail-settings/test {email}` | envía ya; devuelve `html` para la vista previa |
| `GET|PUT settings/branding`, `POST settings/branding/test` | marca del espacio + `resolved` + `platform` |
| `GET|PUT me/notification-preferences` | tipos con estado por canal; los `critical` vienen `locked` |
| `POST me/email-change {email, current_password}` → `POST me/email-change/confirm {code}` | cambio de correo |
| `GET|POST users/{id}/invitation` | estado / reenviar (429 tras 3 por hora) |

## 4. Enviar avisos

```php
use Innertia\Notifications\{Notice, Notifier, Recipients};

Notifier::send(new Notice(
    type: 'voicemail.new',
    title: 'Nuevo mensaje de voz',
    body: 'Anexo 1203 · 0:42',
    url: '/backoffice/voicemail/123',                    // relativa → app.frontend_url
    recipients: Recipients::withPermission('phone.manage', organizationId: $sedeId),
    subjectKey: "voicemail:{$id}",                       // antirrebote/agrupación/resolve
    data: ['voicemail_id' => $id],
    mail: NotificationMail::make()->withSubject('…')->line('…')->action('Escuchar', $url), // opcional
));

Notifier::resolve('central.offline', "central:{$id}");  // la recuperación cierra el aviso abierto
```

- Corre en cola (`innertia.notifications.queue`, por defecto `use-cases`) con el tenant y el `trace_id`.
- Por destinatario: preferencias por canal (los `critical` las ignoran) → agrupación/antirrebote →
  campanita (`user_notifications`, `data.url`) → correo (`NotificationMail` con la marca del espacio,
  fila `queued` en `email_logs`, 3 reintentos con backoff; si se agotan queda `failed` con el motivo).
- Destinatarios: `Recipients::withPermission()`, `withRole()`, `users()`, `members()`, `platformTeam()`
  (owner + staff). Se evalúan en el tenant activo: resuélvelos donde ocurre el evento.
- Tipos de cuenta incluidos (críticos): `account.password_changed`, `account.passkey_added`,
  `account.email_changed`, `account.invitation`.

## 5. Marca

- `Branding::platform()`: configuración de correo de plataforma con respaldo en `config('innertia.mail.*')`.
- `Branding::for($tenant)`: marca del espacio (`TenantBranding`, Settings del tenant `branding.*`:
  `display_name`, `logo_url` o `logo_file_id`, `color`, `footer_address`, `footer_phone`) con
  respaldo campo a campo en la de plataforma; si el espacio tiene marca propia el pie dice
  "Enviado vía {plataforma}".
- Logo por `logo_file_id`: URL firmada de 1 año (pública solo para correos). Un logo inaccesible
  se descarta (HEAD con caché de 1 h; `innertia.mail.verify_logo`) y el correo muestra el nombre.
- Correos de cuenta (invitación, recuperación, OTP, contraseña cambiada, passkey, cambio de correo)
  usan la marca de plataforma. `NotificationMail` y los mailables de negocio, la del espacio.
- En un mailable propio: `public function mailBranding(): ?Branding { return Branding::for($this->tenantId); }`
  y `public function emailType(): ?string { return 'mi.tipo'; }`. El layout acepta además
  `<x-innertia::mail.layout :branding="$branding">`.

## 6. Invitaciones y recuperación

- `SendInvitation($userId)` / `ResendInvitation($userId)`: link de 7 días
  (`innertia.auth.invitation_ttl_days`), reenviar invalida el anterior, máximo 3 por hora.
- El link apunta a `innertia.auth.frontend_activation_path` (por defecto
  `/backoffice/auth/set-password`), que postea a `auth/password/reset`: ese endpoint acepta los
  tokens de invitación (verifica el correo) y si venció responde 422
  `{code: 'invitation_expired', message: 'El link venció, pide uno nuevo.'}`.
- Recuperación: sigue en 60 minutos (`auth.passwords.users.expire`).

## 7. Configuración (`config/innertia.php`)

Todas se leen con valores por defecto en el código: un `config/innertia.php` publicado antiguo sigue funcionando.

| Clave | Default |
|---|---|
| `auth.invitation_ttl_days` | 7 |
| `auth.invitation_resend_per_hour` | 3 |
| `auth.frontend_activation_path` | `/backoffice/auth/set-password` |
| `auth.frontend_reset_path` | `/backoffice/auth/reset-password` |
| `auth.email_change_requires_password` | true |
| `notifications.queue` | `use-cases` |
| `notifications.mail_tries` / `mail_backoff` | 3 / `[60, 300, 900]` |
| `mail.verify_logo` | true |

## 8. Front (innertia-nuxt)

Componentes: `Notifications.Preferences`, `Platform.Emails`, `Platform.MailSettings`,
`Mail.BrandingPreview`, `Mail.BrandingSettings`; campanita de platform con
`<App.NotificationBell endpoint="platform/notifications" all-route="/platform/notifications" />`;
middleware de cambio de clave forzado (`appConfig.innertia.forcePassword`). Ver
`docs/correos-notificaciones.md` de innertia-nuxt.
