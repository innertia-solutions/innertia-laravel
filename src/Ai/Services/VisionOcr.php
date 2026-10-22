<?php

namespace Innertia\Ai\Services;

use Illuminate\Support\Facades\Http;
use Innertia\Ai\ProviderResolver;

/** Extrae texto de imágenes (data URLs) con el proveedor de visión asignado (chat/completions multimodal). */
class VisionOcr
{
    public function extract(array $imageDataUrls): string
    {
        if ($imageDataUrls === []) {
            return '';
        }

        $resolved = (new ProviderResolver)->for('vision');
        if (! $resolved) {
            return '';
        }

        $provider = $resolved['provider'];
        $content = [['type' => 'text', 'text' => 'Transcribe fielmente todo el texto visible en estas imágenes.']];
        foreach ($imageDataUrls as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        $res = Http::withToken((string) $provider->api_key)
            ->acceptJson()
            ->post(rtrim((string) $provider->base_url, '/').'/chat/completions', [
                'model' => $resolved['model'],
                'messages' => [['role' => 'user', 'content' => $content]],
            ])
            ->throw()
            ->json();

        return $res['choices'][0]['message']['content'] ?? '';
    }
}
