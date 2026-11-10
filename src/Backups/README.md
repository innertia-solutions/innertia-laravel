# Innertia\Backups — Motor de respaldos de base de datos

Motor reutilizable para generar, restaurar y eliminar respaldos de la base de datos PostgreSQL
desde el `/platform` de un producto. Almacena los artefactos en un disco dedicado (`backups`),
completamente separado del módulo de Files/Drive para usuarios.

---

## Propósito

- **Dump → compress → store**: ejecuta `pg_dump -Fc` y guarda el artefacto en un disk de
  Laravel Storage (por defecto `backups`) con checksum SHA-256.
- **Restaurar con seguridad**: antes de aplicar un restore destructivo, crea un respaldo de
  seguridad automático. Si ese respaldo falla, el restore se aborta.
- **Queryable desde el platform**: expone un `BackupsController` listo para montar bajo el
  middleware/gate de admin de plataforma del producto.

---

## Configuración

Hay dos niveles:

**Infraestructura** (`config/innertia.php`, bloque `backups`):

| Key | Default | Descripción |
|-----|---------|-------------|
| `innertia.backups.mode` | `app` | Modo de operación: `app`, `saas` u `open` |
| `innertia.backups.disk` | `backups` | Disk de Storage para los artefactos (dedicado, no el Drive de usuarios). Si el producto no lo declara, la lib registra uno local en `storage/app/backups` |

**Política** (horarios + retención): se edita desde **/platform** y se guarda en la tabla
`settings` a nivel plataforma (`Support\BackupSettings`). No hay env ni config de archivo para
esto, y no hay switch de apagado: el respaldo automático corre siempre.

| Setting | Default | Descripción |
|---------|---------|-------------|
| `backups.schedule.times` | `03:00, 11:00, 19:00` | Horarios diarios HH:MM (1–24) |
| `backups.schedule.timezone` | `America/Santiago` | Zona horaria de los horarios |
| `backups.retention.days` | `30` | Antigüedad máxima de un respaldo |
| `backups.retention.keep` | `21` | Máximo de respaldos completados a conservar (7 días × 3) |

Si la BD no responde o no hay valor guardado, rigen los defaults (el schedule se registra igual).

### Endpoints de la política

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/platform/backups/settings` | Política vigente + `defaults`, `next_runs` (ISO), `last_completed`, `enabled: true` |
| `PUT` | `/platform/backups/settings` | Guarda `times[]`, `timezone`, `retention_days` (1–3650), `keep` (1–1000) |

`schedule:run` reconstruye el schedule cada minuto, así que un cambio rige desde el minuto siguiente.

---

## Respaldo automático

`BackupsServiceProvider` registra `innertia:backups:run` en el scheduler del producto (siempre),
una entrada por horario de la política, con `withoutOverlapping()`, `onOneServer()` y `runInBackground()`. El producto solo
necesita un proceso `php artisan schedule:work` (o cron `schedule:run`) con `postgresql-client`.

```bash
php artisan schedule:list             # muestra las 3 entradas innertia:backups:run
php artisan innertia:backups:run      # respaldo síncrono + poda (--no-prune para omitirla)
php artisan innertia:backups:prune    # solo poda (--days= --keep= --dry-run)
```

El comando corre síncrono (un dump grande no cabe en el `$timeout=60` de un UseCase encolado),
se omite si ya hay un respaldo `pending`/`running` de menos de 6 h, y sale con FAILURE si el
dump falla.

### Retención

- Completados (no safety): se borra el que supere `retention.days` **o** quede fuera de los `keep`
  más recientes. El completado más reciente nunca se borra.
- Safety y fallidos: se borran al superar `retention.days`.
- `pending` / `running`: nunca se tocan.

### Trazabilidad

`RunBackup` escribe inicio/fin/error con `Telemetry::log('operational', …)` (trace_id del proceso,
contexto `module=backups`, `backup_id`). Si falla hace `report($e)`: la telemetría lo promueve a
caso por fingerprint y avisa a soporte por correo.

---

## Modos

| Modo | Driver | Estado |
|------|--------|--------|
| `app` | `AppBackupDriver` | Implementado (`pg_dump -Fc` / `pg_restore`) |
| `saas` | `SaasBackupDriver` | Diferido — `BackupManager` lanza `UnsupportedBackupModeException` |
| `open` | `OpenBackupDriver` | Diferido — `BackupManager` lanza `UnsupportedBackupModeException` |

En modo `app` el driver ejecuta:
- **Create**: `pg_dump -Fc --no-owner --no-acl -h host -p port -U user -d dbname > artifact.dump`
- **Restore**: `pg_restore --clean --if-exists -h host -p port -U user -d dbname artifact.dump`

---

## Tabla `backups`

Migración: `database/migrations/app/2025_01_01_000012_create_backups_table.php`
(auto-cargada por la lib en modo `app`).

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | uuid PK | Generado automáticamente (`HasUuids`) |
| `status` | string | `pending` → `running` → `completed` / `failed` |
| `disk` | string | Disk de Storage donde vive el artefacto |
| `path` | string? | Ruta relativa del artefacto dentro del disk |
| `size_bytes` | bigint? | Tamaño del artefacto tras el dump |
| `checksum` | string? | SHA-256 del artefacto |
| `driver` | string | Driver que ejecutó el backup (`app`, `saas`, `open`) |
| `mode` | string | Modo con que se configuró la lib al momento del backup |
| `is_safety` | boolean | `true` si fue creado automáticamente antes de un restore |
| `error` | text? | Mensaje de error si `status = failed` |
| `completed_at` | timestamp? | Momento en que terminó (exitoso o fallido) |
| `created_at` / `updated_at` | timestamps | Estándar Eloquent |

Los respaldos de seguridad (`is_safety = true`) se crean automáticamente antes de cada restore
y quedan **excluidos** del listado por defecto del `index` del controller.

---

## Seguridad en el restore

El endpoint `POST platform/backups/{id}/restore` exige:

1. **Token de confirmación** en el body (`confirmation`): debe coincidir exactamente con el nombre
   de la base de datos (`config('database.connections.pgsql.database')`). Si no coincide, devuelve 422.
2. **Backup de seguridad previo** (síncrono): se crea un `Backup` con `is_safety = true` antes
   de restaurar. Si ese backup no termina en `completed`, se lanza `SafetyBackupFailedException`
   y el restore se **aborta** — nunca se ejecuta `pg_restore` sin un punto de rollback válido.

---

## Montar las rutas en el producto

El `BackupsController` no registra sus propias rutas; el producto las monta bajo el
middleware/gate de administración de plataforma que corresponda:

```php
use Innertia\Backups\Http\Controllers\BackupsController;

// En las rutas del producto, bajo su middleware/gate de platform-admin:
Route::prefix('platform/backups')->group(function () {
    Route::get('settings', [BackupsController::class, 'settings']);
    Route::put('settings', [BackupsController::class, 'updateSettings']);
    Route::get('/', [BackupsController::class, 'index']);
    Route::post('/', [BackupsController::class, 'store']);
    Route::get('{id}/download', [BackupsController::class, 'download']);
    Route::post('{id}/restore', [BackupsController::class, 'restore']);
    Route::delete('{id}', [BackupsController::class, 'destroy']);
});
```

### Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/platform/backups` | Lista respaldos (DataTable, excluye safety) |
| `POST` | `/platform/backups` | Crea y encola un nuevo backup (409 si hay uno en curso) |
| `GET` | `/platform/backups/{id}/download` | Descarga el artefacto `.dump` |
| `POST` | `/platform/backups/{id}/restore` | Restaura (requiere `confirmation`) |
| `DELETE` | `/platform/backups/{id}` | Elimina artefacto del disk y el registro |

---

## Use Cases

| Clase | Descripción |
|-------|-------------|
| `RunBackup` | Ejecuta `BackupDriver::create()`, actualiza status a `completed`/`failed`, loguea en telemetría y reporta el fallo |
| `PruneBackups` | Aplica la retención (`backups.retention.days` / `keep` de settings); devuelve cuántos eliminó |
| `RestoreBackup` | Crea safety backup → verifica que completó → ejecuta `BackupDriver::restore()` |
| `DeleteBackup` | Elimina el artefacto del disk (si existe) y borra el registro |

`RunBackup` y `RestoreBackup` extienden `UseCase` — pueden ejecutarse de forma síncrona con
`.execute()` o en cola con `.onQueue()`.

---

## Nota de despliegue

El entorno que ejecuta el dump necesita tener `pg_dump` y `pg_restore` disponibles en el PATH.
En contenedores Docker esto implica instalar el cliente de PostgreSQL:

```dockerfile
RUN apt-get install -y postgresql-client
```

El disk `backups` debe configurarse en `config/filesystems.php` del producto. Ejemplo con disco local:

```php
'backups' => [
    'driver' => 'local',
    'root'   => storage_path('app/backups'),
],
```
