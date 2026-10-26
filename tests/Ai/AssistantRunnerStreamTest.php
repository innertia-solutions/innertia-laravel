<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Innertia\Ai\Assistant\AssistantRunner;
use Innertia\Ai\Assistant\StreamingTurn;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\Conversation;
use Innertia\Ai\Models\Message;
use Laravel\Ai\Responses\StreamableAgentResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);

    // El stream() del agente resuelve el manager de proveedores de laravel/ai
    // (MultipleInstanceManager) de forma eager; en testbench hay que registrar su
    // service provider para que resuelva. La iteración del stream sigue lazy (sin red).
    $this->app->register(\Laravel\Ai\AiServiceProvider::class);

    $this->migrationsDir = sys_get_temp_dir().'/innertia-ai-stream-'.uniqid();
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

// Nota: el streaming real (token a token + then() persistiendo la respuesta del
// asistente) se verifica manualmente contra un LLM. El stream de laravel/ai es
// lazy — NO se itera acá, así que no hay llamada de red; se testea la parte
// síncrona: conversación creada, mensaje del usuario persistido, DTO devuelto.

it('crea la conversación, persiste el mensaje del usuario y devuelve un StreamingTurn (sin iterar el stream)', function () {
    $turn = (new AssistantRunner)->runStream(user: null, text: 'hola streaming');

    expect($turn)->toBeInstanceOf(StreamingTurn::class)
        ->and($turn->response)->toBeInstanceOf(StreamableAgentResponse::class);

    $conversation = Conversation::find($turn->conversationId);
    expect($conversation)->not->toBeNull();

    $messages = Message::where('conversation_id', $turn->conversationId)->get();
    // Solo el mensaje del usuario (el del asistente se crea en then(), al terminar el stream).
    expect($messages)->toHaveCount(1)
        ->and($messages->first()->role)->toBe('user')
        ->and($messages->first()->content)->toBe('hola streaming');
});

it('continúa una conversación existente pasándole el historial', function () {
    $conversation = Conversation::create(['title' => 'previa']);
    Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'anterior', 'created_at' => now()]);
    Message::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'respuesta previa', 'created_at' => now()]);

    $turn = (new AssistantRunner)->runStream(user: null, text: 'nuevo', conversationId: $conversation->id);

    expect($turn->conversationId)->toBe((string) $conversation->id);
    // 2 previos + el nuevo mensaje del usuario = 3 (el del asistente aún no, va en then()).
    expect(Message::where('conversation_id', $conversation->id)->count())->toBe(3);
});
