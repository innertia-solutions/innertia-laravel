<?php

namespace Innertia\Ai\Transcription;

/**
 * Audio → texto. Adaptadores por tipo de proveedor (MiniMax `asr-1.0`, OpenAI `/audio/transcriptions`).
 * Se obtiene con `Ai::transcriber()` (asignación de uso `transcription`) o `Ai::transcriber('minimax')`.
 *
 * @throws TranscriptionException  fallo del proveedor (`transient()` = vale reintentar: red, 429, 5xx)
 */
interface TranscriptionProvider
{
    /** Clave del proveedor (`ai_providers.key`), para registrar quién transcribió. */
    public function providerKey(): string;

    public function model(): string;

    /**
     * @param  resource|string  $audio  stream abierto o contenido binario
     * @param  array{language?: string, prompt?: string, duration_seconds?: float}  $options
     *         duration_seconds: si el llamador ya la conoce, el adaptador rechaza sin subir el audio cuando excede su límite.
     */
    public function transcribe($audio, string $filename, string $mime = 'audio/wav', array $options = []): TranscriptionResult;

    /** Duración máxima que acepta el proveedor en segundos (null = sin límite conocido). */
    public function maxSeconds(): ?int;
}
