<?php

namespace Innertia\Imports;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Innertia\Imports\Jobs\ProcessImportJob;
use Innertia\Models\File;
use Innertia\Models\Process;
use Innertia\Platform\Events\ProcessCompleted;
use Innertia\Platform\Events\ProcessFailed;
use RuntimeException;

/**
 * Abstract base for file imports (CSV / XLSX).
 *
 * Subclass contract:
 *   - Implement row(array $row): void  — process a single data row
 *   - Optionally override rules(): array — per-row validation (Laravel rules)
 *   - Optionally override chunkSize(): int — rows per chunk (default 100)
 *   - Optionally override defaultChannels: array — notification channels for subscribers
 *
 * Usage:
 *
 *   $file = File::fromRequest($request, 'file');
 *
 *   (new ImportUsers)
 *       ->fromFile($file)
 *       ->subscribable($adminUser, $managerUser)
 *       ->subscribable([$user1, $user2], channels: ['mail'])
 *       ->queue();
 *
 *   // Synchronous (CLI / seeds):
 *   $process = (new ImportUsers)->fromPath('/path/users.csv')->run();
 */
abstract class Importer
{
    protected int   $chunkSize       = 100;
    protected array $defaultChannels = ['database', 'web'];

    private ?File    $file               = null;
    private ?Process $process            = null;
    private array    $pendingSubscribers = [];

    // ── Abstract ──────────────────────────────────────────────────────────────

    abstract public function row(array $row): void;

    // ── Hooks (optional) ──────────────────────────────────────────────────────

    public function rules(): array   { return []; }
    public function messages(): array { return []; }

    // ── Entry points ──────────────────────────────────────────────────────────

    /**
     * Primary entry point — accepts an already-created File model.
     */
    public function fromFile(File $file): static
    {
        $this->file = $file;

        $this->process = Process::start(
            type:        'import',
            category:    static::class,
            triggeredBy: (string) ($file->created_by ?? auth()->id()),
        );

        $this->flushPendingSubscribers();

        return $this;
    }

    /** Shortcut: creates a File from request then calls fromFile(). */
    public function fromRequest(\Illuminate\Http\Request $request, string $field, string $disk = ''): static
    {
        return $this->fromFile(File::fromRequest($request, $field, $disk));
    }

    /** Shortcut: creates a File from an UploadedFile then calls fromFile(). */
    public function fromUploadedFile(UploadedFile $file, string $disk = ''): static
    {
        return $this->fromFile(File::fromUploadedFile($file, $disk));
    }

    /** Shortcut: creates a File from an absolute path then calls fromFile(). */
    public function fromPath(string $absolutePath, string $disk = ''): static
    {
        return $this->fromFile(File::fromPath($absolutePath, $disk));
    }

    // ── Subscribable ──────────────────────────────────────────────────────────

    /**
     * Subscribe users to process completion notifications.
     *
     *   ->subscribable($user1, $user2)                       // defaultChannels for all
     *   ->subscribable($user1, channels: ['mail'])            // named channels for $user1
     *   ->subscribable([$user1, $user2], channels: ['web'])   // array + channels
     */
    public function subscribable(Authenticatable|array $users, array $channels = []): static
    {
        $channels = $channels ?: $this->defaultChannels;
        $users    = is_array($users) ? $users : [$users];

        if ($this->process) {
            foreach ($users as $user) {
                $this->process->subscribe($user, channels: $channels);
            }
        } else {
            $this->pendingSubscribers[] = [$users, $channels];
        }

        return $this;
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    /**
     * Run synchronously. Returns the completed Process.
     */
    public function run(): Process
    {
        $this->ensureReady();

        $rows = $this->readFile();

        $this->process->markProcessing();
        $this->process->addMetadata(['total_rows' => count($rows)]);

        $errors        = [];
        $processedRows = 0;

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            foreach ($chunk as $index => $row) {
                $rowNumber = $index + 1;

                if ($rules = $this->rules()) {
                    $validator = Validator::make($row, $rules, $this->messages());

                    if ($validator->fails()) {
                        $errors[] = ['row' => $rowNumber, 'message' => implode(', ', $validator->errors()->all())];
                        continue;
                    }

                    $row = $validator->validated();
                }

                try {
                    $this->row($row);
                    $processedRows++;
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
                }
            }

            // Update progress after each chunk
            $progress = count($rows) > 0
                ? (int) round(($processedRows / count($rows)) * 100)
                : 100;

            $this->process->updateProgress($progress);
        }

        $this->process->complete([
            'total_rows'     => count($rows),
            'processed_rows' => $processedRows,
            'failed_rows'    => count($errors),
            'errors'         => $errors ?: null,
            'file_id'        => $this->file->id,
        ]);

        $this->process->refresh();
        $this->process->attachFile($this->file);

        $this->dispatchEvent();

        return $this->process;
    }

    /**
     * Dispatch as a queued job. Returns the Process immediately (status: pending).
     */
    public function queue(string $queueName = 'default'): Process
    {
        $this->ensureReady();

        ProcessImportJob::dispatch($this->process, $this->file, static::class)
            ->onQueue($queueName);

        return $this->process;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    public function readFile(): array
    {
        $this->ensureReady();

        $path      = \Illuminate\Support\Facades\Storage::disk($this->file->disk)->path($this->file->path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv'   => $this->readCsv($path),
            'xlsx'  => $this->readXlsx($path),
            default => throw new RuntimeException("Unsupported format: {$extension}. Use csv or xlsx."),
        };
    }

    private function readCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open file: {$path}");
        }

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            fseek($handle, 0);
        }

        $headers = null;

        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $line);
                continue;
            }
            $rows[] = array_combine($headers, array_pad($line, count($headers), null));
        }

        fclose($handle);

        return $rows;
    }

    private function readXlsx(string $path): array
    {
        $rows    = [];
        $headers = null;
        $reader  = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($cell) => $cell->getValue(), $row->getCells());

                if ($headers === null) {
                    $headers = array_map('trim', $values);
                    continue;
                }

                $rows[] = array_combine($headers, array_pad($values, count($headers), null));
            }
            break; // first sheet only
        }

        $reader->close();

        return $rows;
    }

    private function ensureReady(): void
    {
        if (! $this->file || ! $this->process) {
            throw new RuntimeException(
                'No file loaded. Call fromFile(), fromRequest(), or fromPath() first.'
            );
        }
    }

    private function flushPendingSubscribers(): void
    {
        foreach ($this->pendingSubscribers as [$users, $channels]) {
            foreach ($users as $user) {
                $this->process->subscribe($user, channels: $channels);
            }
        }
        $this->pendingSubscribers = [];
    }

    private function dispatchEvent(): void
    {
        if ($this->process->status === 'failed') {
            ProcessFailed::dispatch($this->process);
        } else {
            ProcessCompleted::dispatch($this->process);
        }
    }

    public function getProcess(): ?Process { return $this->process; }
    public function getFile(): ?File       { return $this->file; }

    /** @internal Used by ProcessImportJob to rehydrate the importer. */
    public function hydrateFromJob(Process $process, File $file): static
    {
        $this->process = $process;
        $this->file    = $file;
        return $this;
    }
}
