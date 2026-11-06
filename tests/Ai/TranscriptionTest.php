<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Innertia\Ai\Facades\Ai;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\ProviderPresets;
use Innertia\Ai\Transcription\MiniMaxTranscriptionProvider;
use Innertia\Ai\Transcription\OpenAiTranscriptionProvider;
use Innertia\Ai\Transcription\TranscriptionException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);
    $this->migrationsDir = sys_get_temp_dir().'/innertia-ai-transcription-'.uniqid();
    File::ensureDirectoryExists($this->migrationsDir);
    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])->assertSuccessful();
    $this->aiMigration = require glob($this->migrationsDir.'/*_create_ai_tables*.php')[0];
    $this->aiMigration->up();
});

afterEach(function () {
    $this->aiMigration->down();
    File::deleteDirectory($this->migrationsDir);
});

function minimaxProvider(array $attrs = []): AiProvider
{
    $p = AiProvider::create(array_merge([
        'key' => 'minimax', 'name' => 'MiniMax', 'type' => 'openai-compatible',
        'base_url' => 'https://api.minimax.io/v1', 'api_key' => 'mm-key', 'active' => true,
    ], $attrs));
    ProviderPresets::ensureDefaultModels($p);

    return $p;
}

// ─── MiniMax ────────────────────────────────────────────────────────────────

it('MiniMax: sube el audio a /speech_to_text con modelo, verbose_json e idioma en el header', function () {
    Http::fake(['api.minimax.io/v1/speech_to_text' => Http::response([
        'text' => ' Hola, llamo por mi losartán. ', 'duration' => 12.5, 'trace_id' => 't1', 'n_speakers' => 2,
        'segments' => [
            ['id' => 0, 'start' => 0.0, 'end' => 4.2, 'speaker' => 0, 'text' => ' Hola, '],
            ['id' => 1, 'start' => 4.2, 'end' => 12.5, 'speaker' => 1, 'text' => 'llamo por mi losartán.'],
        ],
    ])]);

    $r = (new MiniMaxTranscriptionProvider('mm-key'))->transcribe('RIFF....', 'call.wav', 'audio/wav', ['language' => 'es']);

    expect($r->text)->toBe('Hola, llamo por mi losartán.')
        ->and($r->duration)->toBe(12.5)
        ->and($r->speakers)->toBe(2)
        ->and($r->provider)->toBe('minimax')
        ->and($r->model)->toBe('asr-1.0')
        ->and($r->segments[1])->toBe(['start' => 4.2, 'end' => 12.5, 'text' => 'llamo por mi losartán.', 'speaker' => 1]);

    Http::assertSent(function (Request $req) {
        $fields = collect($req->data())->pluck('contents', 'name');

        return $req->url() === 'https://api.minimax.io/v1/speech_to_text'
            && $req->hasHeader('Authorization', 'Bearer mm-key')
            && $req->hasHeader('language', 'es')
            && $fields['model'] === 'asr-1.0'
            && $fields['response_format'] === 'verbose_json'
            && $fields->has('file');
    });
});

it('MiniMax: sin saldo (402) lanza TranscriptionException no transitoria con el tipo del proveedor', function () {
    Http::fake(['*' => Http::response(['type' => 'error', 'error' => ['type' => 'insufficient_balance_error', 'message' => 'insufficient balance'], 'request_id' => 'r'], 402)]);

    try {
        (new MiniMaxTranscriptionProvider('mm-key'))->transcribe('x', 'a.wav');
        $this->fail('debía lanzar');
    } catch (TranscriptionException $e) {
        expect($e->status)->toBe(402)
            ->and($e->type)->toBe('insufficient_balance_error')
            ->and($e->transient())->toBeFalse()
            ->and($e->getMessage())->toContain('402');
    }
});

it('MiniMax: 429 y 5xx son transitorios; la red caída también', function () {
    Http::fake(['*' => Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429)]);
    expect(fn () => (new MiniMaxTranscriptionProvider('k'))->transcribe('x', 'a.wav'))
        ->toThrow(fn (TranscriptionException $e) => expect($e->transient())->toBeTrue());

    Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
    expect(fn () => (new MiniMaxTranscriptionProvider('k'))->transcribe('x', 'a.wav'))
        ->toThrow(fn (TranscriptionException $e) => expect($e->transient())->toBeTrue()->and($e->status)->toBe(0));
});

it('MiniMax: un audio de más de 500 s se rechaza sin subirlo', function () {
    Http::fake();

    expect(fn () => (new MiniMaxTranscriptionProvider('k'))->transcribe('x', 'a.wav', 'audio/wav', ['duration_seconds' => 612.4]))
        ->toThrow(fn (TranscriptionException $e) => expect($e->type)->toBe('too_long')->and($e->transient())->toBeFalse());

    Http::assertNothingSent();
});

it('MiniMax: respuesta 200 sin texto es un error', function () {
    Http::fake(['*' => Http::response(['trace_id' => 'x'])]);

    expect(fn () => (new MiniMaxTranscriptionProvider('k'))->transcribe('x', 'a.wav'))
        ->toThrow(TranscriptionException::class, 'sin texto');
});

// ─── OpenAI ─────────────────────────────────────────────────────────────────

it('OpenAI: /audio/transcriptions con idioma, vocabulario y segmentos', function () {
    Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response([
        'text' => 'Llamo al CESFAM.', 'duration' => 3.0, 'segments' => [['start' => 0, 'end' => 3, 'text' => ' Llamo al CESFAM.']],
    ])]);

    $r = (new OpenAiTranscriptionProvider('sk'))->transcribe('x', 'a.wav', 'audio/wav', ['language' => 'es', 'prompt' => 'CESFAM, SOME']);

    expect($r->text)->toBe('Llamo al CESFAM.')->and($r->provider)->toBe('openai')->and($r->model)->toBe('whisper-1')
        ->and($r->segments)->toBe([['start' => 0.0, 'end' => 3.0, 'text' => 'Llamo al CESFAM.']]);
    Http::assertSent(function (Request $req) {
        $f = collect($req->data())->pluck('contents', 'name');

        return $f['language'] === 'es' && $f['prompt'] === 'CESFAM, SOME' && $f['response_format'] === 'verbose_json';
    });
});

it('OpenAI: los modelos gpt-4o-*-transcribe piden json (no aceptan verbose_json)', function () {
    Http::fake(['*' => Http::response(['text' => 'hola'])]);

    (new OpenAiTranscriptionProvider('sk', 'gpt-4o-transcribe'))->transcribe('x', 'a.wav');

    Http::assertSent(fn (Request $req) => collect($req->data())->pluck('contents', 'name')['response_format'] === 'json');
});

// ─── Resolución (Ai::transcriber) ───────────────────────────────────────────

it('los presets registran los modelos de transcripción de MiniMax y OpenAI', function () {
    $mm = minimaxProvider();
    $oa = AiProvider::create(['key' => 'openai', 'name' => 'OpenAI', 'type' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk', 'active' => true]);
    ProviderPresets::ensureDefaultModels($oa);

    expect($mm->models()->pluck('key')->all())->toContain('asr-1.0')
        ->and($oa->models()->pluck('key')->all())->toContain('whisper-1', 'gpt-4o-transcribe');
});

it('Ai::transcriber("minimax") arma el adaptador de MiniMax con su modelo de transcripción', function () {
    minimaxProvider();

    $t = Ai::transcriber('minimax');

    expect($t)->toBeInstanceOf(MiniMaxTranscriptionProvider::class)
        ->and($t->model())->toBe('asr-1.0')
        ->and($t->providerKey())->toBe('minimax')
        ->and($t->maxSeconds())->toBe(500);
});

it('Ai::transcriber() sin clave usa la asignación de uso transcription', function () {
    $oa = AiProvider::create(['key' => 'openai', 'name' => 'OpenAI', 'type' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk', 'active' => true]);
    AiAssignment::create(['use' => 'transcription', 'provider_id' => $oa->id, 'model' => 'gpt-4o-transcribe', 'active' => true]);

    $t = Ai::transcriber();

    expect($t)->toBeInstanceOf(OpenAiTranscriptionProvider::class)->and($t->model())->toBe('gpt-4o-transcribe');
});

it('Ai::transcriber("minimax") respeta el modelo de la asignación si apunta a MiniMax', function () {
    $mm = minimaxProvider();
    AiAssignment::create(['use' => 'transcription', 'provider_id' => $mm->id, 'model' => 'asr-2.0', 'active' => true]);

    expect(Ai::transcriber('minimax')->model())->toBe('asr-2.0');
});

it('Ai::transcriber devuelve null sin proveedor, inactivo o sin API key', function () {
    expect(Ai::transcriber('minimax'))->toBeNull()->and(Ai::transcriber())->toBeNull();

    minimaxProvider(['api_key' => null]);
    expect(Ai::transcriber('minimax'))->toBeNull();

    AiProvider::where('key', 'minimax')->first()->forceFill(['api_key' => 'k', 'active' => false])->save();
    expect(Ai::transcriber('minimax'))->toBeNull();
});
