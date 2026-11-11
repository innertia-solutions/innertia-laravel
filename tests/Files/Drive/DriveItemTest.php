<?php

use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Drive\NativeExports;
use Innertia\Files\Support\TempFile;

it('maps a Drive API file and classifies it', function () {
    $doc = DriveItem::fromApi([
        'id' => 'd1', 'name' => 'Acta', 'mimeType' => 'application/vnd.google-apps.document',
        'version' => '42', 'modifiedTime' => '2026-11-01T10:00:00Z', 'webViewLink' => 'https://docs/d1',
    ]);
    $pdf = DriveItem::fromApi([
        'id' => 'p1', 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => '1234',
        'md5Checksum' => 'abc', 'driveId' => 'shared-1',
    ]);
    $folder = DriveItem::fromApi(['id' => 'f1', 'name' => 'Carpeta', 'mimeType' => DriveItem::FOLDER_MIME]);

    expect($doc->isNative())->toBeTrue();
    expect($doc->revision())->toBe('42');
    expect($pdf->isNative())->toBeFalse();
    expect($pdf->size)->toBe(1234);
    expect($pdf->revision())->toBe('abc');
    expect($pdf->driveId)->toBe('shared-1');
    expect($folder->isFolder())->toBeTrue();
    expect($folder->isNative())->toBeFalse();
    expect($doc->toArray())->toMatchArray(['id' => 'd1', 'is_native' => true, 'is_folder' => false]);
});

it('resolves native export targets for both formats', function () {
    expect(NativeExports::target('application/vnd.google-apps.document', 'office'))
        ->toBe(['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'extension' => 'docx']);
    expect(NativeExports::target('application/vnd.google-apps.spreadsheet', 'pdf'))
        ->toBe(['mime' => 'application/pdf', 'extension' => 'pdf']);
    expect(NativeExports::target('application/vnd.google-apps.form', 'office'))->toBeNull();
    expect(NativeExports::extensionFor('application/vnd.google-apps.presentation', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'))
        ->toBe('pptx');
    expect(NativeExports::extensionFor('application/vnd.google-apps.document', 'text/plain'))->toBeNull();
});

it('creates unique temp files with extension and deletes them quietly', function () {
    $a = TempFile::create('pdf');
    $b = TempFile::create(null);

    expect($a)->toEndWith('.pdf')->and(is_file($a))->toBeTrue();
    expect($b)->not->toBe($a);

    TempFile::delete($a);
    TempFile::delete($a); // no lanza
    TempFile::delete(null);
    expect(is_file($a))->toBeFalse();
    TempFile::delete($b);
});
