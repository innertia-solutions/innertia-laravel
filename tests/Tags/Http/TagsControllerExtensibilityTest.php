<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Innertia\Tags\Http\Controllers\TagsController;
use Innertia\Tags\Models\Tag;

class CustomTagsController extends TagsController
{
    protected function extraStoreRules(): array
    {
        return ['icon' => 'required|string|max:50'];
    }

    protected function extraFields(Request $request, ?Tag $tag = null): array
    {
        return ['icon' => $request->input('icon')];
    }
}

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaTagsMigrateUp();

    \Illuminate\Support\Facades\Schema::table('tags', function ($t) {
        $t->string('icon')->nullable();
    });

    Route::middleware([])->group(function () {
        \Innertia\Tags\Routes::register('tags', CustomTagsController::class);
    });
});

afterEach(fn () => innertiaTagsMigrateDown());

it('applies extra validation rules from subclass', function () {
    $this->postJson('/tags', ['name' => 'X'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['icon']);
});

it('persists extra fields from subclass', function () {
    $this->postJson('/tags', ['name' => 'X', 'icon' => 'star'])
        ->assertCreated();

    expect(Tag::first()->icon)->toBe('star');
});
