<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Innertia\Ai\Assistant\AssistantRunner;
use Innertia\Ai\Assistant\BackgroundTurn;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\Conversation;
use Innertia\Ai\Models\Message;
use Laravel\Ai\Jobs\BroadcastAgent;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);

    // broadcastOnQueue despacha el job BroadcastAgent; registramos el provider de
    // laravel/ai como en el test de streaming. Con Queue::fake() el job NO corre
    // (no hay red); se testea el despacho + la parte síncrona.
    $this->app->register(\Laravel\Ai\AiServiceProvider::class);

    $this->migrationsDir = sys_get_temp_dir().'/innertia-ai-bg-'.uniqid();
    File::ensureDirectoryExists($this->migrationsDir);
    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])->assertSuccessful();
    $this->aiMigration = require glob($this->migrationsDir.'/*_create_ai_tables*.php')[0];
    $this->aiMigration->up();

    $provider = AiProvider::create([
        'key' => 'fake', 'name' => 'Fake', 'type' => 'fake',
        'base_url' => 'https://fake.test/v1', 'api_key' => 'k', 'active' => true,
    ]);
    AiAssignment::create(['use' => 'chat', 'provider_id' => $provider->id, 'model' => 'test-model', 'active' => true]);
});

afterEach(function () {
    $this->aiMigration->down();
    if (isset($this->migrationsDir) && File::isDirectory($this->migrationsDir)) {
        File::deleteDirectory($this->migrationsDir);
    }
});

// Nota: el broadcast real token-a-token (el job corriendo el stream y emitiendo al
// canal + then() persistiendo la respuesta) se verifica manualmente con un worker.
// Acá: Queue::fake() → se testea el despacho del job + la parte síncrona.

it('despacha BroadcastAgent, crea la conversación + mensaje del usuario y devuelve un BackgroundTurn', function () {
    Queue::fake();

    $turn = (new AssistantRunner)->runInBackground(user: null, text: 'generá 5 preguntas', channel: 'agent-run.abc123');

    expect($turn)->toBeInstanceOf(BackgroundTurn::class)
        ->and($turn->channel)->toBe('agent-run.abc123');

    $conversation = Conversation::find($turn->conversationId);
    expect($conversation)->not->toBeNull();

    $messages = Message::where('conversation_id', $turn->conversationId)->get();
    // Solo el mensaje del usuario (el del asistente se persiste en then(), al terminar el job).
    expect($messages)->toHaveCount(1)
        ->and($messages->first()->role)->toBe('user')
        ->and($messages->first()->content)->toBe('generá 5 preguntas');

    Queue::assertPushed(BroadcastAgent::class);
    // Por defecto los jobs largos de IA van a la cola dedicada 'agents'.
    Queue::assertPushedOn('agents', BroadcastAgent::class);
});

it('respeta una cola distinta si se le pasa', function () {
    Queue::fake();

    (new AssistantRunner)->runInBackground(user: null, text: 'x', channel: 'agent-run.q', queue: 'default');

    Queue::assertPushedOn('default', BroadcastAgent::class);
});

it('continúa una conversación existente', function () {
    Queue::fake();

    $conversation = Conversation::create(['title' => 'previa']);
    Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'anterior', 'created_at' => now()]);

    $turn = (new AssistantRunner)->runInBackground(
        user: null, text: 'seguimos', channel: 'agent-run.xyz', conversationId: $conversation->id,
    );

    expect($turn->conversationId)->toBe((string) $conversation->id);
    // 1 previo + el nuevo mensaje del usuario = 2 (el del asistente va en then()).
    expect(Message::where('conversation_id', $conversation->id)->count())->toBe(2);
    Queue::assertPushed(BroadcastAgent::class);
});
