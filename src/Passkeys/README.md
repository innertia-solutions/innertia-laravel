# Innertia\Passkeys — Ingreso con passkeys (WebAuthn)

Módulo opt-in para que los usuarios ingresen con la huella, el rostro o el PIN de su dispositivo
(passkeys WebAuthn) como complemento de la contraseña. El lado front (composable `usePasskeys` y
componentes) vive en `innertia-nuxt`; ver innertia-nuxt.

---

## Activación

Desactivado por defecto. Se habilita por env (bloque `passkeys` de `config/innertia.php`):

| Env | Config | Default | Descripción |
|-----|--------|---------|-------------|
| `PASSKEYS_ENABLED` | `innertia.passkeys.enabled` | `false` | Activa el módulo y registra las rutas |
| `PASSKEYS_RP_ID` | `innertia.passkeys.rp_id` | host de `app.frontend_url` (si no, `app.url`) | Dominio al que se amarra la passkey |
| `PASSKEYS_RP_NAME` | `innertia.passkeys.rp_name` | `app.name` | Nombre que ve el usuario al registrar |
| `PASSKEYS_ORIGINS` | `innertia.passkeys.origins` | origen de `app.frontend_url` | Lista separada por comas de orígenes permitidos |
| — | `innertia.passkeys.challenge_ttl` | `300` | Vida del challenge en segundos |

Las rutas las registran solas `Auth\Routes` y `Backoffice\Routes` cuando está habilitado. Un producto
que compone rutas a mano puede llamar `Innertia\Passkeys\Routes::login()`, `platformLogin()` y `account()`.

> **Subdominios por tenant**: el `rp_id` debe ser el dominio padre (ej. `pomely.cl`), de lo contrario
> la passkey solo vale en un subdominio. **Apps móviles nativas (Capacitor)** están fuera de alcance.

---

## Rutas

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| `POST` | `auth/passkey/login/options` | pública | Challenge para ingresar |
| `POST` | `auth/passkey/login` | pública | Body: `key`, `response`, `contexts[]` (ordenados). Entra al primer contexto permitido y responde `{token, user, context}` |
| `POST` | `platform/auth/passkey/login/options` | pública | Challenge (cuentas de plataforma) |
| `POST` | `platform/auth/passkey/login` | pública | Body: `key`, `response` |
| `GET` | `auth/me/passkeys` | token | Lista las passkeys propias |
| `POST` | `auth/me/passkeys/options` | token | Challenge de registro |
| `POST` | `auth/me/passkeys` | token | Registra (201). Body: `key`, `response`, `name?` |
| `PATCH` | `auth/me/passkeys/{uuid}` | token | Renombra (`name`) |
| `DELETE` | `auth/me/passkeys/{uuid}` | token | Elimina |
| — | `platform/auth/me/passkeys*` | token platform | Mismo autogestión para cuentas de plataforma |
| `GET` | `users/{id}/passkeys` | admin | Lista las passkeys de un usuario |
| `DELETE` | `users/{id}/passkeys` | admin | Revoca todas ("dispositivo perdido") |

Los endpoints `users/{id}/passkeys` son del `UsersController` del backoffice: los `UsersController`
de los productos que lo extienden los heredan con el mismo alcance y autorización que `show()`.

---

## Reglas de ingreso

- El ingreso con passkey **salta OTP y 2FA** (la passkey ya es un factor fuerte).
- **Sigue aplicando**: cambio forzado de contraseña, verificación de correo y el rechazo de
  `separate_identity` (`PlatformAccountException`: una cuenta de plataforma no entra por el login de tenant).
- Una cuenta de plataforma con cambio de contraseña forzado pendiente **no puede registrar passkeys**
  hasta cambiarla (lista de exenciones de `Authenticate`).

## Seguridad

- `userVerification` requerido.
- Se rechaza la regresión del contador (verificación con `lock for update`).
- Challenges de un solo uso, con TTL.
- Cualquier falla responde un 422 genérico: "No pudimos verificar la passkey."; el detalle se
  registra una sola vez en Telemetry, canal `operational`, módulo `passkeys`.
- Cambiar o resetear la contraseña **no** revoca las passkeys.

## Recuperación

```bash
php artisan passkeys:revoke {email}   # revoca todas las passkeys de la cuenta
```
