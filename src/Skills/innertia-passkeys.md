---
name: innertia-passkeys
description: Ingreso con passkeys (WebAuthn) con Innertia\Passkeys — encendido por defecto con interruptor en BD (/platform), rutas auth/passkey/login y auth/me/passkeys, salta OTP/2FA, rp_id, revocación. Trigger para "passkey", "WebAuthn", "huella", "passkeys/settings", "passkeys:revoke", "rp_id".
---

# Innertia Passkeys

Detalle completo en `src/Passkeys/README.md` del paquete. Front: `usePasskeys` + componentes en innertia-nuxt.

## Activación

Encendido por defecto; el interruptor vive en BD (Settings de plataforma: `passkeys.enabled`, `passkeys.allow_subdomains`), sin env. Se apaga desde /platform: registrar `\Innertia\Passkeys\Routes::platformSettings('')` en el grupo platform del producto (`GET|PUT platform/passkeys/settings`). Apagado: login y `me/passkeys*` dan 404; admin `users/{id}/passkeys` y `passkeys:revoke` siguen. Front: `GET auth/passkey/status` / `platform/auth/passkey/status` → `{enabled}` (público); `/status` trae `features.passkeys`. Overrides avanzados opcionales (normalmente innecesarios): `PASSKEYS_RP_ID`, `PASSKEYS_RP_NAME`, `PASSKEYS_ORIGINS`.
`rp_id` por defecto = host de `app.frontend_url` (si no, `app.url`); challenge 300 s.

## Rutas (se registran solas desde Auth\Routes / Backoffice\Routes)

- Públicas: `auth/passkey/login/options`, `auth/passkey/login` (`key`, `response`, `contexts[]` ordenados
  → entra al primer contexto permitido, devuelve `{token,user,context}`); `platform/auth/passkey/login*`.
- Autenticadas: `auth/me/passkeys` (GET, POST `/options`, POST 201, PATCH/DELETE `{uuid}`);
  cuentas de plataforma en `platform/auth/me/passkeys*`.
- Admin: `GET|DELETE users/{id}/passkeys`, heredado por los UsersController de los productos.

## Cómo lo consume un producto

1. Registrar `Routes::platformSettings('')` en el grupo platform; `PASSKEYS_RP_ID` solo si hay subdominios por tenant (dominio padre).
2. Usar el front de innertia-nuxt; el BFF sella el token en la sesión httpOnly.
3. Correr `php artisan migrate` (crea `passkeys`; la migración se carga siempre).
   En QA/prod, si el deploy no migra, correrlo a mano. Las rutas se montan solas.
4. El cache store por defecto guarda los challenges y debe ser compartido entre réplicas (redis/database;
   `array` no sirve).
5. Front/BFF: sellar la sesión solo si la respuesta trae `token` (puede venir `requires_password_change` /
   `requires_email_verification` + `context`). El login de plataforma devuelve token con
   `force_password_change` pendiente y `Authenticate` responde 403 `password_change_required`: redirigir
   al cambio de contraseña como en el login con contraseña.

## Gotchas

- `rp_id` debe coincidir con el host del front (o ser su dominio padre). En dev: `localhost`.
- Productos con subdominios por tenant: `PASSKEYS_RP_ID` = dominio padre + `allow_subdomains` activo en /platform
  (con `PASSKEYS_ORIGINS` = origen padre).
- Passkey salta OTP y 2FA, pero respeta cambio forzado de contraseña, verificación de correo y
  `separate_identity`.
- Cuenta de plataforma con cambio forzado pendiente no puede registrar passkeys.
- Errores: 422 genérico; detalle en Telemetry (operational, módulo `passkeys`).
- Resetear contraseña no revoca passkeys; usar `php artisan passkeys:revoke {email}`.
- Apps nativas (Capacitor) fuera de alcance.
