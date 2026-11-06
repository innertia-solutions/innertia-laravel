<?php

namespace Innertia\Ai\Transcription;

use Illuminate\Support\Facades\Http;

/**
 * API OpenAI (o compatible): `POST {base_url}/audio/transcriptions` multipart, `response_format=verbose_json`,
 * con `language` y `prompt` (vocabulario). Límite de OpenAI: 25 MB por archivo (no por duración).
 */
class OpenAiTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(
        private string $apiKey,
        private string $model = 'whisper-1',
        private string $baseUrl = 'https://api.openai.com/v1',
        private string $key = 'openai',
        private int $timeout = 180,
    ) {}

    public function providerKey(): string
    {
        return $this->key;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function maxSeconds(): ?int
    {
        return null;
    }

    public function transcribe($audio, string $filename, string $mime = 'audio/wav', array $options = []): TranscriptionResult
    {
        // gpt-4o-transcribe / gpt-4o-mini-transcribe solo aceptan json/text (sin segmentos).
        $verbose = ! str_starts_with($this->model, 'gpt-');
        try {
            $res = Http::timeout($this->timeout)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->attach('file', $audio, $filename, ['Content-Type' => $mime])
                ->post(rtrim($this->baseUrl, '/').'/audio/transcriptions', array_filter([
                    'model' => $this->model,
                    'language' => $options['language'] ?? null,
                    'prompt' => $options['prompt'] ?? null,
                    'response_format' => $verbose ? 'verbose_json' : 'json',
                ]));
        } catch (\Throwable $e) {
            throw new TranscriptionException('El proveedor de transcripción no responde: '.mb_substr($e->getMessage(), 0, 200), 0, null, $e);
        }

        if (! $res->successful()) {
            $type = $res->json('error.type') ?? $res->json('error.code');
            $msg = (string) ($res->json('error.message') ?? mb_substr($res->body(), 0, 200));
            throw new TranscriptionException('El proveedor de transcripción respondió '.$res->status().': '.mb_substr($msg, 0, 200), $res->status(), is_string($type) ? $type : 'http_error');
        }
        if (! is_string($res->json('text'))) {
            throw new TranscriptionException('El proveedor de transcripción devolvió una respuesta sin texto.', $res->status(), 'invalid_response');
        }

        return new TranscriptionResult(
            trim((string) $res->json('text')),
            array_values(array_map(fn ($s) => [
                'start' => (float) ($s['start'] ?? 0), 'end' => (float) ($s['end'] ?? 0), 'text' => trim((string) ($s['text'] ?? '')),
            ], (array) $res->json('segments', []))),
            $res->json('duration') !== null ? (float) $res->json('duration') : null,
            $this->model,
            $this->key,
        );
    }
}
