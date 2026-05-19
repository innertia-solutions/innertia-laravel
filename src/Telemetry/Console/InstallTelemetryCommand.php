<?php

namespace Innertia\Telemetry\Console;

use Illuminate\Console\Command;

class InstallTelemetryCommand extends Command
{
    protected $signature = 'innertia:telemetry:install
                            {--migrate : Correr migrate automáticamente después de publicar}
                            {--force  : Sobreescribir la migración si ya existe}';

    protected $description = 'Publica la migración de telemetry_events (para modo standalone o both)';

    public function handle(): int
    {
        $isSaas = config('innertia.mode') === 'saas';
        $mode   = config('telemetry.mode', 'remote');

        if ($mode === 'remote') {
            $this->warn('El modo de telemetría es "remote" — no necesitas tabla local.');
            $this->line('Cambia <comment>TELEMETRY_MODE=standalone</comment> o <comment>TELEMETRY_MODE=both</comment> en tu .env para usar almacenamiento local.');
            return self::SUCCESS;
        }

        $stub = $isSaas
            ? __DIR__ . '/../../../database/migrations/telemetry/create_telemetry_events_table_saas.php'
            : __DIR__ . '/../../../database/migrations/telemetry/create_telemetry_events_table.php';

        $filename   = date('Y_m_d_His') . '_create_telemetry_events_table.php';
        $targetPath = database_path('migrations/' . $filename);

        // Evitar duplicados
        $existing = glob(database_path('migrations/*_create_telemetry_events_table.php'));
        if (! empty($existing) && ! $this->option('force')) {
            $this->warn('La migración ya existe: ' . basename($existing[0]));
            $this->line('Usa <comment>--force</comment> para sobreescribir.');
            return self::SUCCESS;
        }

        if (! empty($existing) && $this->option('force')) {
            foreach ($existing as $file) {
                unlink($file);
            }
        }

        copy($stub, $targetPath);

        $variant = $isSaas ? '(SaaS — con tenant_id)' : '(App)';
        $this->info("Migración publicada {$variant}: database/migrations/{$filename}");

        if ($this->option('migrate') || $this->confirm('¿Correr php artisan migrate ahora?', false)) {
            $this->call('migrate');
        }

        return self::SUCCESS;
    }
}
