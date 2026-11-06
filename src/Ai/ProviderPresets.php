<?php

namespace Innertia\Ai;

use Innertia\Ai\Models\AiModel;
use Innertia\Ai\Models\AiProvider;

/**
 * Modelos que trae por defecto cada tipo de proveedor. Al crear un proveedor (UI o
 * seeder) se registran solos, para no tener que tipear las claves exactas del API.
 * Idempotente: no duplica ni pisa modelos que ya existan.
 */
class ProviderPresets
{
    /** Modelo de embedding por defecto: 1536 dimensiones (= columna pgvector del RAG). */
    public const OPENAI_EMBEDDING = 'text-embedding-3-small';

    /** Modelos de transcripción por defecto (audio → texto). */
    public const OPENAI_TRANSCRIPTION = 'whisper-1';

    public const MINIMAX_TRANSCRIPTION = 'asr-1.0';

    public const MODELS = [
        'openai' => [
            ['key' => self::OPENAI_EMBEDDING, 'name' => 'text-embedding-3-small (embedding)', 'capabilities' => ['embedding']],
            ['key' => self::OPENAI_TRANSCRIPTION, 'name' => 'Whisper (transcripción)', 'capabilities' => ['transcription']],
            ['key' => 'gpt-4o-transcribe', 'name' => 'GPT-4o Transcribe (transcripción)', 'capabilities' => ['transcription']],
        ],
        'minimax' => [
            ['key' => 'MiniMax-M3.1-Flash', 'name' => 'MiniMax M3.1 Flash', 'capabilities' => ['chat', 'vision']],
            ['key' => 'MiniMax-M3',         'name' => 'MiniMax M3',          'capabilities' => ['chat', 'vision']],
            ['key' => self::MINIMAX_TRANSCRIPTION, 'name' => 'MiniMax ASR (transcripción)', 'capabilities' => ['transcription']],
        ],
    ];

    /** @return list<array{key:string,name:string,capabilities:list<string>}> */
    public static function modelsFor(AiProvider $provider): array
    {
        // Por tipo; MiniMax es "openai-compatible", así que también se reconoce por key.
        return self::MODELS[$provider->type] ?? self::MODELS[$provider->key] ?? [];
    }

    /** Registra los modelos por defecto que falten. Devuelve cuántos creó. */
    public static function ensureDefaultModels(AiProvider $provider): int
    {
        $created = 0;
        foreach (self::modelsFor($provider) as $m) {
            $model = AiModel::firstOrCreate(
                ['provider_id' => $provider->id, 'key' => $m['key']],
                ['name' => $m['name'], 'capabilities' => $m['capabilities'], 'active' => true],
            );
            $created += (int) $model->wasRecentlyCreated;
        }

        return $created;
    }
}
