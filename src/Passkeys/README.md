# Innertia\Passkeys — Ingreso con passkeys (WebAuthn)

Módulo opt-in para que los usuarios ingresen con la huella, el rostro o el PIN de su dispositivo
(passkeys WebAuthn) como complemento de la contraseña. El lado front (composable `usePasskeys` y
componentes) vive en `innertia-nuxt`; ver innertia-nuxt.

---

## Activación

**Encendido por defecto**: un producto que actualiza la lib ofrece passkeys de inmediato. El interruptor
vive en la BD (tabla `settings`, nivel plataforma, tenant_id NULL) y se cambia desde /platform; no hay
env para eso. Si la BD no responde o no hay valor guardado, rige el default (encendido).

Settings (`Innertia\Passkeys\Support\PasskeySettings`): `passkeys.enabled` (default `true`) y
`passkeys.allow_subdomains` (default `false`).

Registrar el endpoint de administración dentro del grupo platform del producto (aplica su auth):

```php
\Innertia\Passkeys\Routes::platformSettings('');   // GET|PUT platform/passkeys/settings
```

`GET` responde `{enabled, allow_subdomains, rp_id, origins}` (`rp_id`/`origins` solo lectura, para mostrar
el dominio en uso). `PUT` recibe `enabled` (boolean, requerido) y `allow_subdomains` (boolean, opcional).

Overrides avanzados por env (bloque `passkeys` de `config/innertia.php`), normalmente innecesarios:

| Env | Config | Default | Descripción |
|-----|--------|---------|-------------|
| `PASSKEYS_RP_ID` | `innertia.passkeys.rp_id` | host de `app.frontend_url` (si no, `app.url`) | Dominio al que se amarra la passkey |
| `PASSKEYS_RP_NAME` | `innertia.passkeys.rp_name` | `app.name` | Nombre que ve el usuario al registrar |
| `PASSKEYS_ORIGINS` | `innertia.passkeys.origins` | origen de `app.frontend_url` | Lista separada por comas de orígenes permitidos |
| — | `innertia.passkeys.challenge_ttl` | `300` | Vida del challenge en segundos |

Hay que correr `php artisan migrate`: crea la tabla `passkeys` (la migración se carga siempre). En QA/prod,
si el deploy no migra, correrlo a mano.

Las rutas se registran siempre (no pueden depender de un valor en BD); con el interruptor apagado, el
login y la autogestión (`passkey/login*`, `me/passkeys*`) responden 404 vía `EnsurePasskeysEnabled`.
Las rutas de admin `users/{id}/passkeys` y el comando `passkeys:revoke` siguen funcionando apagado, para
poder revocar. El front, antes del login, consulta `GET auth/passkey/status` o
`GET platform/auth/passkey/status` (públicos, throttle 60/min, sin 404): `{enabled: bool}`. `/status`
también trae `features.passkeys`.

Los challenges viven en el store de caché por defecto, que debe ser compartido entre réplicas
(`redis` o `database`); `array` no funciona entre requests.

Las rutas las registran solas `Auth\Routes` y `Backoffice\Routes` siempre. Un producto
que compone rutas a mano puede llamar `Innertia\Passkeys\Routes::login()`, `platformLogin()` y `account()`.

> **Subdominios por tenant**: el `rp_id` debe ser el dominio padre (ej. `pomely.cl`), de lo contrario
> la passkey solo vale en un subdominio. Además hay que activar `allow_subdomains` en /platform (`PUT platform/passkeys/settings`) y setear
> `PASSKEYS_ORIGINS` = el origen padre (ej. `https://pomely.cl`); sin eso se rechazan los orígenes `acme.pomely.cl`. **Apps móviles nativas (Capacitor)** están fuera de alcance.

---

## Rutas

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| `GET` | `auth/passkey/status` · `platform/auth/passkey/status` | pública | `{enabled}` (nunca 404) |
| `GET`/`PUT` | `platform/passkeys/settings` | platform (producto) | Interruptor; ver Activación |
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

## Consumo desde el front / BFF

- Sellar la sesión solo cuando la respuesta trae `token`: el login puede devolver
  `requires_password_change` / `requires_email_verification` + `context` sin token.
- El login de plataforma con passkey devuelve token aunque haya `force_password_change` pendiente, y
  `Authenticate` bloquea después con 403 `password_change_required`: redirigir al cambio de contraseña,
  igual que el login con contraseña.

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
