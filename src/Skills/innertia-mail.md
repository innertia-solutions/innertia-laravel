---
name: innertia-mail
description: Use when writing or customizing emails — InnertiaMailable, branding por tenant, WelcomeMail, EmailVerificationMail, OtpMail, PasswordChangedMail, NotificationMail fluent builder. Trigger for "mailable", "enviar email", "logo en el email", "brand_color", "OTP mail", "verification email".
---

# Innertia — Mail

Capa sobre `Illuminate\Mail\Mailable` con branding configurable, layout master, y un fluent builder (`NotificationMail`) para casos comunes sin escribir Blade.

## InnertiaMailable — base

```php
// src/Mail/InnertiaMailable.php
abstract class InnertiaMailable extends Mailable {
    use Queueable, SerializesModels;

    abstract public function subject(): string;
    abstract public function view(): string;

    public function build() { /* renderiza con layout master */ }
    public function payload(): array { /* props públicas serializadas */ }
}
```

Convención: subclases declaran `subject()` y `view()`, dejan `build()` a la base.

## Mailables built-in (en `src/Auth/Mailables/`)

| Clase | Constructor | View blade |
|---|---|---|
| `WelcomeMail` | `($user, ?string $temporaryPassword)` | `innertia::mail.welcome` |
| `EmailVerificationMail` | `($user, string $url)` | `innertia::mail.email-verification` |
| `OtpMail` | `($otp, string $action)` | `innertia::mail.otp` |
| `PasswordChangedMail` | `($user)` | `innertia::mail.password-changed` |

`OtpMail::$action` puede ser `'login'`, `'email_verification'`, `'password_reset'`, `'sensitive_action'`.

Estos mailables se disparan automáticamente desde los UseCases de Auth (Login, Register, etc.) cuando los flags correspondientes están activos.

## Branding del email

Config global (en `config/innertia.php`):

```php
'mail' => [
    'logo_url'    => env('MAIL_LOGO_URL', null),
    'brand_color' => env('MAIL_BRAND_COLOR', '#6366f1'),
],
```

Para branding **por tenant**: override en runtime via Settings (ver `innertia-config`):

```php
Settings::set('mail.logo_url', 'https://cdn.example.com/tenants/acme/logo.png');
Settings::set('mail.brand_color', '#10b981');
```

El layout maestro lee primero de Settings, luego del config global.

## Layout maestro

Path publicable: `resources/views/vendor/innertia/mail/layout.blade.php` (después de `php artisan vendor:publish --tag=innertia-views`).

Variables disponibles:

- `$logoUrl` — del Settings tenant o config global
- `$brandColor` — del Settings tenant o config global
- `$appName` — `config('app.name')`
- `$slot` — contenido del mailable
- `$footer` — opcional, slot adicional

## Escribir un mailable custom

```php
namespace App\Domains\Invoices\Mails;

use App\Domains\Invoices\Models\Invoice;
use Innertia\Mail\InnertiaMailable;

class InvoicePaidMail extends InnertiaMailable {
    public function __construct(public readonly Invoice $invoice) {}

    public function subject(): string {
        return "Pago confirmado — Factura #{$this->invoice->number}";
    }

    public function view(): string {
        return 'emails.invoice-paid';
    }
}
```

Y el Blade en `resources/views/emails/invoice-paid.blade.php`:

```blade
<h2>Pago recibido</h2>
<p>Hola {{ $invoice->customer->name }},</p>
<p>Confirmamos la recepción del pago de tu factura <strong>#{{ $invoice->number }}</strong>.</p>
<p>Total: ${{ number_format($invoice->total, 2) }}</p>
```

Las propiedades públicas del mailable están disponibles automáticamente en el Blade. El layout maestro envuelve el contenido con el branding.

Enviar:

```php
Mail::to($invoice->customer->email)->queue(new InvoicePaidMail($invoice));
```

## NotificationMail fluent builder

Para emails transaccionales simples (notificaciones, alertas) sin escribir Blade:

```php
use Innertia\Mail\NotificationMail;

$mail = (new NotificationMail())
    ->title('Tu factura está vencida')
    ->line('Hola {{ $name }}, hace 5 días que la factura #{{ $number }} debió pagarse.')
    ->panel(type: 'warning', message: 'Por favor, regulariza tu situación cuanto antes.')
    ->table([
        ['Factura',  $invoice->number],
        ['Vencida',  $invoice->due_date->diffForHumans()],
        ['Monto',    '$' . number_format($invoice->total, 2)],
    ])
    ->action('Ver factura', "https://app.example.com/invoices/{$invoice->id}")
    ->line('Si ya pagaste, ignorá este mensaje.')
    ->with(['name' => $customer->name, 'number' => $invoice->number]);

Mail::to($customer->email)->queue($mail);
```

### Métodos del builder

| Método | Descripción |
|---|---|
| `title(string)` | H1 del email |
| `line(string)` | Párrafo de texto (soporta variables blade) |
| `action(string $label, string $url)` | Botón CTA con brand color |
| `table(array $rows)` | Tabla key/value |
| `panel(type: 'info'\|'success'\|'warning'\|'danger', message: string)` | Bloque destacado |
| `with(array)` | Bindings para `{{ $variables }}` en líneas |
| `attachment(string $path)` | Adjuntar archivo |

## Disparar desde DomainEvents

Cuando un evento declara `channels()` con `'mail'` y un `toMail()` que devuelve un `InnertiaMailable`, el `DomainEventRouter` envía a los suscriptores del `subscribable()`:

```php
public function toMail(): ?InnertiaMailable {
    return new InvoicePaidMail($this->invoice);
}
```

Ver `innertia-events` para el flujo completo.

## Queue por defecto

`InnertiaMailable` usa `Queueable` — `Mail::queue($mailable)` despacha al queue worker. Por default queue `default`; configurable via:

```php
Mail::to($user)->queue((new InvoicePaidMail($invoice))->onQueue('emails'));
```

## Testing

```php
use Illuminate\Support\Facades\Mail;

Mail::fake();

// ... acción que dispara el email

Mail::assertQueued(InvoicePaidMail::class, function ($mail) use ($invoice) {
    return $mail->invoice->id === $invoice->id;
});
```

## Anti-patrones

- ❌ Hardcodear logos/colores en el Blade del mailable — perdés el branding por tenant. Usar el layout maestro.
- ❌ Pasar datos sensibles en `payload()` — termina en logs y queue serialization. Cargar via lazy queries en el mailable.
- ❌ Enviar mail directo sin queue (`Mail::send`) en requests HTTP — bloquea la respuesta. Usar `Mail::queue` siempre.

## Skills relacionados

- `innertia-events` — `toMail()` desde DomainEvents
- `innertia-config` — bloque `mail` (logo_url, brand_color)
- `innertia-permissions` — endpoints de auth que disparan WelcomeMail, OtpMail, etc.
