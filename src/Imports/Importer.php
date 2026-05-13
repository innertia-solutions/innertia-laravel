<?php

namespace Innertia\Imports;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Innertia\Imports\Events\ImportCompleted;
use Innertia\Imports\Events\ImportFailed;
use Innertia\Imports\Jobs\ProcessImportJob;
use Innertia\Models\ImportRecord;
use RuntimeException;

/**
 * Abstract base for file imports (CSV / XLSX).
 *
 * Subclass contract:
 *   - Implement row(array $row): void  — process a single data row
 *   - Optionally override rules(): array — per-row validation rules
 *   - Optionally override chunkSize(): int — rows per chunk (default 100)
 *   - Optionally override defaultChannels(): array — notification channels
 *
 * Usage:
 *
 *   (new ImportUsers)
 *       ->fromRequest($request, 'file')
 *       ->subscribable($adminUser, $managerUser)
 *       ->queue();
 *
 *   (new ImportUsers)
 *       ->fromPath(storage_path('seeds/users.csv'))
 *       ->run();  // synchronous
 */
abstract class Importer
{
    protected int   $chunkSize       = 100;
    protected array $defaultChannels = ['database'];

    private ?ImportRecord $record           = null;
    private array         $pendingSubscribers = [];

    // ── Abstract ──────────────────────────────────────────────────────────────

    /**
     * Process a single validated row.
     * Called for every row that passes validation.
     * Throw any exception to mark the row as failed (import continues).
     */
    abstract public function row(array $row): void;

    // ── Hooks (optional override) ─────────────────────────────────────────────

    /**
     * Laravel validation rules applied to each row.
     * Rows that fail are recorded as errors and skipped.
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [];
    }

    // ── Entry points ──────────────────────────────────────────────────────────

    /**
     * Load file from an HTTP request field.
     * The file is permanently stored in Storage before queueing.
     */
    public function fromRequest(\Illuminate\Http\Request $request, string $field): static
    {
        $uploaded = $request->file($field);

        if (! $uploaded instanceof UploadedFile) {
            throw new RuntimeException("Field \"{$field}\" is not a valid uploaded file.");
        }

        return $this->fromUploadedFile($uploaded);
    }

    /**
     * Load file from an already-uploaded UploadedFile instance.
     */
    public function fromUploadedFile(UploadedFile $file): static
    {
        $disk = config('filesystems.default', 'local');
        $path = $file->store('imports/' . now()->format('Y/m'), $disk);

        $this->record = ImportRecord::create([
            'type'              => static::class,
            'status'            => 'pending',
            'disk'              => $disk,
            'file_path'         => $path,
            'original_filename' => $file->getClientOriginalName(),
        ]);

        $this->flushPendingSubscribers();

        return $this;
    }

    /**
     * Load file from an absolute filesystem path (for seeding / CLI use).
     */
    public function fromPath(string $absolutePath): static
    {
        if (! file_exists($absolutePath)) {
            throw new RuntimeException("File not found: {$absolutePath}");
        }

        // Copy into Storage so the job always reads from a consistent location
        $disk     = config('filesystems.default', 'local');
        $filename = basename($absolutePath);
        $path     = 'imports/' . now()->format('Y/m') . '/' . uniqid() . '_' . $filename;

        Storage::disk($disk)->put($path, file_get_contents($absolutePath));

        $this->record = ImportRecord::create([
            'type'              => static::class,
            'status'            => 'pending',
            'disk'              => $disk,
            'file_path'         => $path,
            'original_filename' => $filename,
        ]);

        $this->flushPendingSubscribers();

        return $this;
    }

    // ── Subscribable ──────────────────────────────────────────────────────────

    /**
     * Subscribe users/models to import completion events.
     * Call after fromRequest() / fromPath().
     *
     *   ->subscribable($user1, $user2)
     *   ->subscribable($adminUser, channels: ['mail', 'database'])
     */
    public function subscribable(Authenticatable ...$subscribers): static
    {
        if ($this->record) {
            foreach ($subscribers as $subscriber) {
                $this->record->subscribe($subscriber, channels: $this->defaultChannels);
            }
        } else {
            // Buffer until record is created
            $this->pendingSubscribers[] = [$subscribers, $this->defaultChannels];
        }

        return $this;
    }

    /**
     * Same as subscribable() but with explicit channel override.
     *
     *   ->subscribableWith(['mail'], $user1, $user2)
     */
    public function subscribableWith(array $channels, Authenticatable ...$subscribers): static
    {
        if ($this->record) {
            foreach ($subscribers as $subscriber) {
                $this->record->subscribe($subscriber, channels: $channels);
            }
        } else {
            $this->pendingSubscribers[] = [$subscribers, $channels];
        }

        return $this;
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    /**
     * Execute the import synchronously in the current process.
     * Returns the completed ImportRecord.
     */
    public function run(): ImportRecord
    {
        $this->ensureRecord();

        $rows = $this->readFile();

        $this->record->markProcessing(count($rows));

        $errors = [];

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            foreach ($chunk as $index => $row) {
                $rowNumber = $index + 1;

                // Validate
                if ($rules = $this->rules()) {
                    $validator = Validator::make($row, $rules, $this->messages());

                    if ($validator->fails()) {
                        $errors[] = [
                            'row'     => $rowNumber,
                            'message' => implode(', ', $validator->errors()->all()),
                        ];
                        continue;
                    }

                    $row = $validator->validated();
                }

                // Process
                try {
                    $this->row($row);
                    $this->record->incrementProcessed();
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
                }
            }
        }

        if (! empty($errors)) {
            $this->record->addErrors($errors);
        }

        $this->record->markCompleted();
        $this->record->refresh();

        $this->dispatchEvent();

        return $this->record;
    }

    /**
     * Dispatch the import as a queued job.
     * Returns the ImportRecord immediately (status: pending).
     * Poll its status to track progress.
     */
    public function queue(string $queueName = 'default'): ImportRecord
    {
        $this->ensureRecord();

        ProcessImportJob::dispatch($this->record, static::class)
            ->onQueue($queueName);

        return $this->record;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Read all rows from the stored file.
     * Returns array of associative arrays (first row = headers).
     */
    public function readFile(): array
    {
        $this->ensureRecord();

        $path      = Storage::disk($this->record->disk)->path($this->record->file_path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv'  => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException("Unsupported import format: {$extension}. Use csv or xlsx."),
        };
    }

    private function readCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open file: {$path}");
        }

        // Strip BOM if present
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

        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(
                    fn ($cell) => $cell->getValue(),
                    $row->getCells()
                );

                if ($headers === null) {
                    $headers = array_map('trim', $values);
                    continue;
                }

                $rows[] = array_combine($headers, array_pad($values, count($headers), null));
            }

            // Only first sheet
            break;
        }

        $reader->close();

        return $rows;
    }

    private function ensureRecord(): void
    {
        if (! $this->record) {
            throw new RuntimeException(
                'No file loaded. Call fromRequest() or fromPath() before run() / queue().'
            );
        }
    }

    private function flushPendingSubscribers(): void
    {
        foreach ($this->pendingSubscribers as [$subscribers, $channels]) {
            foreach ($subscribers as $subscriber) {
                $this->record->subscribe($subscriber, channels: $channels);
            }
        }

        $this->pendingSubscribers = [];
    }

    private function dispatchEvent(): void
    {
        if ($this->record->status === 'failed') {
            ImportFailed::dispatch($this->record);
        } else {
            ImportCompleted::dispatch($this->record);
        }
    }

    public function getRecord(): ?ImportRecord
    {
        return $this->record;
    }

    /**
     * Rehydrate the importer with an existing ImportRecord.
     * Used internally by ProcessImportJob — do not call manually.
     *
     * @internal
     */
    public function hydrateRecord(ImportRecord $record): static
    {
        $this->record = $record;

        return $this;
    }
}
