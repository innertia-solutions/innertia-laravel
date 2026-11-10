---
name: innertia-backups
description: Respaldos de la base de datos con Innertia\Backups — pg_dump -Fc a un disk dedicado, respaldo automático siempre activo (3 veces al día por defecto), política de horarios/retención editable desde /platform, poda, restore con safety-backup y token. Trigger para "respaldos", "backups", "pg_dump", "restore", "retención", "innertia:backups:run".
---

# Innertia Backups

Motor de respaldos de la BD PostgreSQL. Activo en todo producto desde que la lib está instalada;
detalle completo en `src/Backups/README.md` del paquete.

## Respaldo automático (siempre activo, sin configurar nada)

La lib registra en el scheduler del producto `innertia:backups:run` a las **03:00, 11:00 y 19:00
(America/Santiago)** por defecto, cada uno con `withoutOverlapping()`, `onOneServer()` y
`runInBackground()`. **No hay switch de apagado ni env**: siempre corre. Cada corrida hace
`pg_dump -Fc`, guarda en el disk `backups` y poda según la retención.

Requisitos del producto (deploy):
- Un contenedor/proceso con `php artisan schedule:work` (o loop/cron de `schedule:run`).
- `postgresql-client` (`pg_dump`/`pg_restore`) en esa imagen.
- Cache con locks (redis/database/file) para `onOneServer()`.
- Si el producto no declara el disk `backups`, la lib registra uno local en `storage/app/backups`
  → en Docker montar un volumen ahí (o declarar un disk S3/R2) para que sobreviva redeploys.

```bash
php artisan schedule:list                   # una entrada innertia:backups:run por horario
php artisan innertia:backups:run            # respaldo manual síncrono + poda (--no-prune)
php artisan innertia:backups:prune          # solo poda (--days= --keep= --dry-run)
```

## Política editable desde /platform (no env, no archivos)

Horarios y retención viven en la tabla `settings` (nivel plataforma) vía `Support\BackupSettings`:
`backups.schedule.times` (json), `backups.schedule.timezone`, `backups.retention.days`,
`backups.retention.keep`. Defaults: 03/11/19 h, America/Santiago, 30 días, 21 respaldos.
Si la BD no responde o no hay valor, rigen los defaults (el schedule se registra igual).

El producto monta en su grupo de /platform (owner):

```php
Route::get('backups/settings', [BackupsController::class, 'settings']);
Route::put('backups/settings', [BackupsController::class, 'updateSettings']);
```

`GET` devuelve `{times, timezone, retention_days, keep, enabled: true, defaults, next_runs, last_completed}`;
`PUT` valida `times[]` HH:MM (1–24), `timezone`, `retention_days` 1–3650, `keep` 1–1000.
`schedule:run` reconstruye el schedule cada minuto: el cambio rige desde el minuto siguiente.

`config('innertia.backups')` solo guarda infraestructura: `mode` (`BACKUP_MODE`) y `disk` (`BACKUP_DISK`).

## Retención (`PruneBackups`)

- Completados (no safety): se borra el que supere `retention.days` **o** quede fuera de los `keep`
  más recientes. El completado más reciente **nunca** se borra.
- Safety y fallidos: se borran al superar `retention.days`.
- `pending`/`running`: nunca.
Borra artefacto + registro (`DeleteBackup`).

## Trazabilidad

`RunBackup` loguea inicio/fin/error con `Telemetry::log('operational', ...)` (trace_id del proceso,
`module=backups`). Si falla, marca el registro `failed` y hace `report($e)` → la telemetría lo
promueve a caso por fingerprint y avisa a soporte por correo. El comando sale con FAILURE.
Los cambios de política desde /platform también quedan logueados.

## Restore y endpoints

`BackupsController` (index/store/download/restore/destroy/settings/updateSettings) se monta bajo el
gate de platform del producto. Restore exige `confirmation` = nombre de la BD y crea un safety-backup
previo; si ese falla, aborta. Ver README del módulo.
