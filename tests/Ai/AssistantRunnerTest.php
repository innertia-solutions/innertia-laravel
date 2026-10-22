<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Innertia\Ai\Assistant\AssistantRunner;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\Conversation;
use Innertia\Ai\Models\Message;
use Innertia\Ai\Models\UsageEvent;

uses(RefreshDatabase::class);

// ─── Schema setup (same approach as ResolverTest) ─────────────────────────────

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);

    $this->migrationsDir = sys_get_temp_dir().'/innertia-ai-runner-'.uniqid();
    File::ensureDirectoryExists($this->migrationsDir);

    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])->assertSuccessful();

    $file = glob($this->migrationsDir.'/*_create_ai_tables*.php')[0];
    $this->aiMigration = require $file;
    $this->aiMigration->up();

    // Chat provider + `chat` assignment (model `test-model`). No `assistant`
    // assignment → the runner falls through to `chat`.
    $provider = AiProvider::create([
        'key' => 'fake',
        'name' => 'Fake',
        'type' => 'fake',
        'base_url' => 'https://fake.test/v1',
        'api_key' => 'k',
        'active' => true,
    ]);

    AiAssignment::create([
        'use' => 'chat',
        'provider_id' => $provider->id,
        'model' => 'test-model',
        'active' => true,
    ]);
});

afterEach(function () {
    $this->aiMigration->down();

    if (isset($this->migrationsDir) && File::isDirectory($this->migrationsDir)) {
        File::deleteDirectory($this->migrationsDir);
    }
});

// ─── Fakes ────────────────────────────────────────────────────────────────────

function makeFakeModel(): object
{
    return new class implements \Innertia\Ai\Assistant\Contracts\AssistantModel
    {
        public array $lastHistory = [];

        public string $lastModel = '';

        public function respond(string $instructions, array $history, string $userText, array $attachments, iterable $tools, string $model): \Innertia\Ai\Assistant\AssistantReply
        {
            $this->lastHistory = $history;
            $this->lastModel = $model;

            return new \Innertia\Ai\Assistant\AssistantReply('respuesta fake', 11, 7);
        }
    };
}

// ─── Tests ────────────────────────────────────────────────────────────────────

it('runs a turn: persists user+assistant messages, logs usage, returns the reply', function () {
    $fake = makeFakeModel();
    $runner = new AssistantRunner($fake);

    $r = $runner->run(null, 'hola');

    expect($r['reply'])->toBe('respuesta fake');

    $conversation = Conversation::find($r['conversation_id']);
    expect($conversation)->not->toBeNull();

    $messages = Message::where('conversation_id', $conversation->id)->orderBy('created_at')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('hola')
        ->and($messages[1]->role)->toBe('assistant')
        ->and($messages[1]->content)->toBe('respuesta fake');

    $usage = UsageEvent::where('use', 'assistant')->first();
    expect($usage)->not->toBeNull()
        ->and($usage->model)->toBe('test-model')
        ->and((int) $usage->total_tokens)->toBe(18);

    expect($fake->lastModel)->toBe('test-model')
        ->and($r['usage']['prompt_tokens'])->toBe(11);
});

it('continues an existing conversation and passes prior history to the model', function () {
    $fake = makeFakeModel();
    $runner = new AssistantRunner($fake);

    $r = $runner->run(null, 'hola');
    $runner->run(null, 'otra', conversationId: $r['conversation_id']);

    expect($fake->lastHistory)->toHaveCount(2);

    $count = Message::where('conversation_id', $r['conversation_id'])->count();
    expect($count)->toBe(4);
});

it('does not call vision when there are no images', function () {
    $fake = makeFakeModel();

    $visionFake = new class extends \Innertia\Ai\Services\VisionOcr
    {
        public function extract(array $i): string
        {
            throw new \RuntimeException('should not be called');
        }
    };

    $runner = new AssistantRunner($fake, $visionFake);

    $r = $runner->run(null, 'hola');

    expect($r['reply'])->toBe('respuesta fake');
});
