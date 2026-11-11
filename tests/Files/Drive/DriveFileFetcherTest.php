<?php

use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\Exceptions\DriveExportTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotImportableException;
use Innertia\Files\Drive\DriveItem;

beforeEach(function () {
    require_once __DIR__ . '/helpers.php';
    $this->drive = driveTestBoot();
    $this->fetcher = new DriveFileFetcher($this->drive);
});

afterEach(fn () => driveTestShutdown());

it('downloads a binary keeping its name, mime and extension', function () {
    $item = $this->drive->file('p1', 'Informe.PDF', '%PDF', mime: 'application/pdf');

    $fetched = $this->fetcher->fetch($item, 'office');

    expect(file_get_contents($fetched->localPath))->toBe('%PDF');
    expect($fetched->name)->toBe('Informe.PDF');
    expect($fetched->mime)->toBe('application/pdf');
    expect($fetched->extension)->toBe('pdf');
    expect($fetched->exportMime)->toBeNull();
    $fetched->delete();
    expect(is_file($fetched->localPath))->toBeFalse();
});

it('exports natives to office or pdf and appends the extension', function () {
    $doc = $this->drive->native('d1', 'Acta', 'document', [DOCX => 'DOCX', 'application/pdf' => 'PDF']);

    $office = $this->fetcher->fetch($doc, 'office');
    $pdf = $this->fetcher->fetch($doc, 'pdf');

    expect([$office->name, $office->extension, $office->exportMime])->toBe(['Acta.docx', 'docx', DOCX]);
    expect(file_get_contents($pdf->localPath))->toBe('PDF');
    expect($pdf->name)->toBe('Acta.pdf');
    $office->delete();
    $pdf->delete();
});

it('reuses a forced export mime (refresh in the same format)', function () {
    $doc = $this->drive->native('d1', 'Acta', 'document', [DOCX => 'DOCX', 'application/pdf' => 'PDF']);

    $fetched = $this->fetcher->fetch($doc, 'office', forcedExportMime: 'application/pdf');

    expect($fetched->exportMime)->toBe('application/pdf');
    expect($fetched->extension)->toBe('pdf');
    $fetched->delete();
});

it('does not duplicate the extension when the native name already has it', function () {
    $doc = $this->drive->native('d1', 'Acta.docx', 'document', [DOCX => 'DOCX']);

    $fetched = $this->fetcher->fetch($doc, 'office');

    expect($fetched->name)->toBe('Acta.docx');
    $fetched->delete();
});

it('rejects forms, shortcuts and folders as not importable', function () {
    $form = $this->drive->native('f1', 'Encuesta', 'form', []);
    $short = new DriveItem('s1', 'Atajo', DriveItem::SHORTCUT_MIME);
    $folder = $this->drive->folder('c1', 'Carpeta');

    foreach ([$form, $short, $folder] as $item) {
        expect(fn () => $this->fetcher->fetch($item, 'office'))->toThrow(DriveItemNotImportableException::class);
    }
});

it('maps size limits', function () {
    config()->set('innertia.files.drive.max_file_size', 3);
    $big = $this->drive->file('b1', 'big.txt', 'abcdef');
    $doc = $this->drive->native('d1', 'Acta', 'document', [DOCX => 'DOCX']);
    $this->drive->exportTooLarge[] = 'd1';

    expect(fn () => $this->fetcher->fetch($big, 'office'))->toThrow(DriveFileTooLargeException::class);
    expect(fn () => $this->fetcher->fetch($doc, 'office'))->toThrow(DriveExportTooLargeException::class);
    expect(in_array('download:b1', $this->drive->calls, true))->toBeFalse(); // el tamaño conocido se valida antes de bajar
});
