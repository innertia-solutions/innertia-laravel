<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Support\StorageUsage;
use Innertia\Files\UseCases\ReplaceFileContents;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../helpers/versions.php';
    innertiaFilesMigrateUp();
    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

it('sums current bytes plus superseded versions, including trashed files', function () {
    $a = versionsUpload('aaaa');                       // 4
    (new ReplaceFileContents($a, versionsTmpFile('aaaaaa'), FileVersionSource::Replace))->execute(); // vigente 6, versión 4
    $b = versionsUpload('bb');                          // 2
    $b->trash();

    expect(StorageUsage::totals())->toBe([
        'current_bytes'  => 8,
        'versions_bytes' => 4,
        'total_bytes'    => 12,
    ]);
});

it('accepts a scope over the files query', function () {
    $a = versionsUpload('aaaa');
    versionsUpload('bb');

    $totals = StorageUsage::totals(fn ($q) => $q->whereKey($a->id));

    expect($totals['current_bytes'])->toBe(4);
});
