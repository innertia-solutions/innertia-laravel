<?php

use Innertia\Files\Drive\Exceptions\DriveImportTooLargeException;
use Innertia\Files\Drive\Import\DriveImportPlanner;

beforeEach(function () {
    require_once __DIR__ . '/helpers.php';
    $this->drive = driveTestBoot();
});

afterEach(fn () => driveTestShutdown());

it('expands folders recursively keeping parents before children', function () {
    $this->drive->folder('A', 'Auditoría');
    $this->drive->file('a1', 'acta.pdf', 'x', 'A');
    $this->drive->folder('B', '2026', 'A');
    $this->drive->file('b1', 'informe.txt', 'y', 'B');
    $this->drive->file('solo', 'suelto.txt', 'z');

    $plan = (new DriveImportPlanner($this->drive, 100))->plan(['A', 'solo']);

    expect(array_map(fn ($f) => [$f['item']->id, $f['parent_key']], $plan->folders))->toBe([['A', null], ['B', 'A']]);
    expect(array_map(fn ($f) => [$f['item']->id, $f['folder_key']], $plan->files))->toBe([['a1', 'A'], ['b1', 'B'], ['solo', null]]);
    expect($plan->missing)->toBe([]);
    expect($plan->fileCount())->toBe(3);
});

it('records ids that do not exist instead of failing', function () {
    $this->drive->file('ok', 'ok.txt', 'x');

    $plan = (new DriveImportPlanner($this->drive, 100))->plan(['ok', 'nope']);

    expect($plan->missing)->toBe(['nope']);
    expect($plan->fileCount())->toBe(1);
});

it('ignores repeated ids and cycles', function () {
    $this->drive->folder('A', 'A');
    $this->drive->file('a1', 'a.txt', 'x', 'A');
    $this->drive->children['A'][] = 'A'; // ciclo artificial

    $plan = (new DriveImportPlanner($this->drive, 100))->plan(['A', 'A', 'a1']);

    expect(count($plan->folders))->toBe(1);
    expect(count($plan->files))->toBe(1);
});

it('fails before downloading anything when the selection is too big', function () {
    $this->drive->folder('A', 'A');
    foreach (range(1, 4) as $i) {
        $this->drive->file("f$i", "f$i.txt", 'x', 'A');
    }

    (new DriveImportPlanner($this->drive, 3))->plan(['A']);
})->throws(DriveImportTooLargeException::class);
