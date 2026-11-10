---
name: innertia-backups
description: Respaldos de la base de datos con Innertia\Backups — pg_dump -Fc a un disk dedicado, respaldo automático 3 veces al día por defecto, retención/poda, restore con safety-backup y token, endpoints de /platform. Trigger para "respaldos", "backups", "pg_dump", "restore", "retención", "innertia:backups:run".
---

# Innertia Backups

Motor de respaldos de la BD PostgreSQL. Activo en todo producto desde que la lib está instalada;
detalle completo en `src/Backups/README.md` del paquete.

## Respaldo automático (por defecto, sin configurar nada)

La lib registra en el scheduler del producto `innertia:backups:run` a las **03:00, 11:00 y 19:00
(America/Santiago)**, cada uno con `withoutOverlapping()`, `onOneServer()` y `runInBackground()`.
Cada corrida hace `pg_dump -Fc`, guarda en el disk `backups` y luego poda según la retención.

Requisitos del producto (deploy):
- Un contenedor/proceso con `php artisan schedule:work` (o cron con `schedule:run`).
- `postgresql-client` (`pg_dump`/`pg_restore`) en esa imagen.
- Cache con locks (redis/database) para `onOneServer()`.
- Si el producto no declara el disk `backups`, la lib registra uno local en `storage/app/backups`
  → en Docker montar un volumen ahí (o declarar un disk S3/R2) para que sobreviva redeploys.

```bash
php artisan schedule:list                   # 3 entradas innertia:backups:run
php artisan innertia:backups:run            # respaldo manual síncrono + poda (--no-prune)
php artisan innertia:backups:prune          # solo poda (--days= --keep= --dry-run)
```

## Configuración (`config('innertia.backups')`)

```php
'backups' => [
    'mode'           => env('BACKUP_MODE', 'app'),
    'disk'           => env('BACKUP_DISK', 'backups'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30), // antigüedad máxima
    'keep'           => (int) env('BACKUP_KEEP', 21),           // máx. completados (0 = sin tope)
    'schedule' => [
        'enabled'  => (bool) env('BACKUP_SCHEDULE_ENABLED', true),
        'times'    => ['03:00', '11:00', '19:00'],
        'timezone' => env('BACKUP_SCHEDULE_TIMEZONE', 'America/Santiago'),
    ],
],
```

Cada key se lee con default en código (`Support\BackupSettings`): un bloque `backups` parcial en el
`config/innertia.php` del producto (merge shallow) no rompe el schedule.

## Retención (`PruneBackups`)

- Completados (no safety): se borra el que supere `retention_days` **o** quede fuera de los `keep`
  más recientes. El completado más reciente **nunca** se borra.
- Safety y fallidos: se borran al superar `retention_days`.
- `pending`/`running`: nunca.
Borra artefacto + registro (`DeleteBackup`).

## Trazabilidad

`RunBackup` loguea inicio/fin/error con `Telemetry::log('operational', ...)` (trace_id del proceso,
`module=backups`). Si falla, marca el registro `failed` y hace `report($e)` → la telemetría lo
promueve a caso por fingerprint y avisa a soporte por correo. El comando sale con FAILURE.

## Restore y endpoints

`BackupsController` (index/store/download/restore/destroy) se monta bajo el gate de platform del
producto. Restore exige `confirmation` = nombre de la BD y crea un safety-backup previo; si ese
falla, aborta. Ver README del módulo.
