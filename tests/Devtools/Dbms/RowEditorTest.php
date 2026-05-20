<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Devtools\Dbms\RowEditor;

beforeEach(function () {
    Schema::create('test_widgets', function (Blueprint $table) {
        $table->id();
        $table->string('label');
        $table->timestamps();
    });

    DB::table('test_widgets')->insert(['id' => 1, 'label' => 'Old', 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    Schema::dropIfExists('test_widgets');
});

it('updates a single column of a row', function () {
    $updated = RowEditor::update(
        table:  'test_widgets',
        id:     1,
        column: 'label',
        value:  'New',
    );

    expect($updated)->toBeTrue();
    expect(DB::table('test_widgets')->where('id', 1)->value('label'))->toBe('New');
});

it('returns false when row does not exist', function () {
    $updated = RowEditor::update(
        table:  'test_widgets',
        id:     999,
        column: 'label',
        value:  'X',
    );

    expect($updated)->toBeFalse();
});
