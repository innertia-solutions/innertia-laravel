<?php

namespace Innertia\Ai\Transcription;

use Illuminate\Support\Facades\Http;

/**
 * MiniMax ASR (`asr-1.0`): `POST {base_url}/speech_to_text` multipart (file, model, response_format) con el
 * idioma en el header `language`. Límites del proveedor: 500 s y 50 MB por archivo (400/413). No acepta
 * vocabulario/prompt. Errores con el status HTTP real y cuerpo `{"type":"error","error":{"type","message"}}`
 * (402 insufficient_balance_error, 401, 429, 5xx…). Doc: https://platform.minimax.io/docs/guides/speech-to-text
 */
class MiniMaxTranscriptionProvider implements TranscriptionProvider
{
    public const MAX_SECONDS = 500;

    public function __construct(
        private string $apiKey,
        private string $model = 'asr-1.0',
        private string $baseUrl = 'https://api.minimax.io/v1',
        private string $key = 'minimax',
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
        return self::MAX_SECONDS;
    }

    public function transcribe($audio, string $filename, string $mime = 'audio/wav', array $options = []): TranscriptionResult
    {
        $seconds = $options['duration_seconds'] ?? null;
        if ($seconds !== null && $seconds > self::MAX_SECONDS) {
            throw new TranscriptionException(
                'MiniMax transcribe audios de hasta '.self::MAX_SECONDS.' s (este dura '.(int) round($seconds).' s).', 400, 'too_long',
            );
        }

        try {
            $res = Http::timeout($this->timeout)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->withHeaders(array_filter(['language' => $options['language'] ?? null]))
                ->attach('file', $audio, $filename, ['Content-Type' => $mime])
                ->post(rtrim($this->baseUrl, '/').'/speech_to_text', [
                    'model' => $this->model,
                    'response_format' => 'verbose_json',
                ]);
        } catch (\Throwable $e) {
            throw new TranscriptionException('MiniMax no responde: '.mb_substr($e->getMessage(), 0, 200), 0, null, $e);
        }

        if (! $res->successful()) {
            $type = $res->json('error.type');
            $msg = (string) ($res->json('error.message') ?? mb_substr($res->body(), 0, 200));
            throw new TranscriptionException('MiniMax respondió '.$res->status().($type ? " ($type)" : '').': '.mb_substr($msg, 0, 200), $res->status(), is_string($type) ? $type : 'http_error');
        }
        if (! is_string($res->json('text'))) {
            throw new TranscriptionException('MiniMax devolvió una respuesta sin texto.', $res->status(), 'invalid_response');
        }

        return new TranscriptionResult(
            trim((string) $res->json('text')),
            array_values(array_map(fn ($s) => array_filter([
                'start' => (float) ($s['start'] ?? 0),
                'end' => (float) ($s['end'] ?? 0),
                'text' => trim((string) ($s['text'] ?? '')),
                'speaker' => $s['speaker'] ?? null,
            ], fn ($v) => $v !== null), (array) $res->json('segments', []))),
            $res->json('duration') !== null ? (float) $res->json('duration') : null,
            $this->model,
            $this->key,
            $res->json('n_speakers') !== null ? (int) $res->json('n_speakers') : null,
        );
    }
}
