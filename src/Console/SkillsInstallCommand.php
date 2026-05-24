<?php

namespace Innertia\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Instala los skills de Claude Code del paquete en el proyecto consumidor.
 *
 * Destino default: .claude/skills/innertia/
 * Cada skill es un .md con frontmatter YAML (name, description) que Claude Code
 * carga automáticamente cuando trabaja en este proyecto.
 *
 * Se recomienda re-ejecutar este comando tras un `composer update` del paquete
 * para refrescar los skills con los últimos cambios.
 */
class SkillsInstallCommand extends Command
{
    protected $signature = 'innertia:skills:install
        {--path=.claude/skills/innertia : Destino dentro del proyecto (default: .claude/skills/innertia)}
        {--force : Sobrescribir skills existentes}';

    protected $description = 'Install Innertia Claude Code skills into this project (.claude/skills/innertia/).';

    public function handle(): int
    {
        $source = realpath(__DIR__ . '/../Skills');
        if ($source === false || ! is_dir($source)) {
            $this->error("No se encontró el directorio de skills en el paquete: {$source}");
            return self::FAILURE;
        }

        $relativeDest = $this->option('path');
        $dest = base_path($relativeDest);
        File::ensureDirectoryExists($dest);

        $files = collect(File::files($source))
            ->filter(fn ($f) => $f->getExtension() === 'md');

        if ($files->isEmpty()) {
            $this->warn('No hay skills disponibles en el paquete.');
            return self::SUCCESS;
        }

        $force     = (bool) $this->option('force');
        $installed = 0;
        $skipped   = 0;

        foreach ($files as $file) {
            $target = $dest . DIRECTORY_SEPARATOR . $file->getFilename();
            $exists = File::exists($target);

            if ($exists && ! $force) {
                $this->line("  <comment>skip</comment>   {$file->getFilename()} (use --force para sobrescribir)");
                $skipped++;
                continue;
            }

            File::copy($file->getPathname(), $target);
            $this->line('  <info>' . ($exists ? 'update' : 'create') . "</info> {$file->getFilename()}");
            $installed++;
        }

        $this->newLine();
        $this->info("Instalados {$installed} skill(s) en {$relativeDest}/");
        if ($skipped > 0) {
            $this->line("Saltados {$skipped} skill(s) que ya existen. Usa --force para sobrescribir.");
        }
        $this->newLine();
        $this->line('Tip: agregá .claude/skills/innertia/ a tu repo para que el equipo comparta el contexto de Claude.');

        return self::SUCCESS;
    }
}
