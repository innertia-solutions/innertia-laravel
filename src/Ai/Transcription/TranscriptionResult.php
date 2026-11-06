<?php

namespace Innertia\Ai\Transcription;

/** Resultado de una transcripción. segments: [{start, end, text, speaker?}]. */
final class TranscriptionResult
{
    public function __construct(
        public readonly string $text,
        public readonly array $segments = [],
        public readonly ?float $duration = null,
        public readonly ?string $model = null,
        public readonly ?string $provider = null,
        public readonly ?int $speakers = null,
    ) {}
}
