---
name: innertia-passkeys
description: Ingreso con passkeys (WebAuthn) con Innertia\Passkeys — opt-in por PASSKEYS_ENABLED, rutas auth/passkey/login y auth/me/passkeys, salta OTP/2FA, rp_id, revocación. Trigger para "passkey", "WebAuthn", "huella", "PASSKEYS_ENABLED", "passkeys:revoke", "rp_id".
---

# Innertia Passkeys

Detalle completo en `src/Passkeys/README.md` del paquete. Front: `usePasskeys` + componentes en innertia-nuxt.

## Activación

`PASSKEYS_ENABLED=true`. Opcionales: `PASSKEYS_RP_ID`, `PASSKEYS_RP_NAME`, `PASSKEYS_ORIGINS` (coma).
`rp_id` por defecto = host de `app.frontend_url` (si no, `app.url`); challenge 300 s.

## Rutas (se registran solas desde Auth\Routes / Backoffice\Routes)

- Públicas: `auth/passkey/login/options`, `auth/passkey/login` (`key`, `response`, `contexts[]` ordenados
  → entra al primer contexto permitido, devuelve `{token,user,context}`); `platform/auth/passkey/login*`.
- Autenticadas: `auth/me/passkeys` (GET, POST `/options`, POST 201, PATCH/DELETE `{uuid}`);
  cuentas de plataforma en `platform/auth/me/passkeys*`.
- Admin: `GET|DELETE users/{id}/passkeys`, heredado por los UsersController de los productos.

## Cómo lo consume un producto

1. Setear `PASSKEYS_ENABLED=true` (y `PASSKEYS_RP_ID` si hay subdominios por tenant: dominio padre).
2. Usar el front de innertia-nuxt; el BFF sella el token en la sesión httpOnly.
3. Nada más en el backend: las rutas se montan solas.

## Gotchas

- `rp_id` debe coincidir con el host del front (o ser su dominio padre). En dev: `localhost`.
- Productos con subdominios por tenant: `rp_id` = dominio padre.
- Passkey salta OTP y 2FA, pero respeta cambio forzado de contraseña, verificación de correo y
  `separate_identity`.
- Cuenta de plataforma con cambio forzado pendiente no puede registrar passkeys.
- Errores: 422 genérico; detalle en Telemetry (operational, módulo `passkeys`).
- Resetear contraseña no revoca passkeys; usar `php artisan passkeys:revoke {email}`.
- Apps nativas (Capacitor) fuera de alcance.
