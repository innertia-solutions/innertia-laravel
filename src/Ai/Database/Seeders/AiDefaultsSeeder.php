<?php

namespace Innertia\Ai\Database\Seeders;

use Illuminate\Database\Seeder;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiModel;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\McpServer;
use Innertia\Ai\Models\RagCollection;
use Innertia\Ai\ProviderPresets;

/**
 * Defaults del módulo de IA: proveedores MiniMax (chat/visión) y OpenAI (embedding
 * text-embedding-3-small por defecto), sin keys; asignaciones por uso, colección RAG `default`
 * y el server MCP `question-bank`. Idempotente — se puede correr varias veces.
 * Las credenciales (api_key) se cargan luego por UI/seed del producto.
 */
class AiDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $minimax = AiProvider::firstOrCreate(
            ['key' => 'minimax'],
            [
                'name'     => 'MiniMax',
                'type'     => 'openai-compatible',
                'base_url' => 'https://api.minimax.io/v1',
                'api_key'  => null,
                'active'   => true,
            ],
        );

        // embo-01 queda disponible en MiniMax, pero el embedding por defecto es OpenAI.
        AiModel::firstOrCreate(
            ['provider_id' => $minimax->id, 'key' => 'embo-01'],
            ['name' => 'embo-01 (embedding)', 'capabilities' => ['embedding'], 'active' => true],
        );
        ProviderPresets::ensureDefaultModels($minimax);

        $openai = AiProvider::firstOrCreate(
            ['key' => 'openai'],
            [
                'name'     => 'OpenAI',
                'type'     => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'api_key'  => null,
                'active'   => true,
            ],
        );
        ProviderPresets::ensureDefaultModels($openai);

        // MiniMax-M3.1-Flash es multimodal (texto + visión) vía el mismo endpoint
        // OpenAI-compatible, así que cubre chat/assistant/vision.
        $assignments = [
            'chat'      => 'MiniMax-M3.1-Flash',
            'assistant' => 'MiniMax-M3.1-Flash',
            'vision'    => 'MiniMax-M3.1-Flash',
            'embedding' => ProviderPresets::OPENAI_EMBEDDING,
        ];
        foreach ($assignments as $use => $model) {
            AiAssignment::firstOrCreate(
                ['use' => $use],
                ['provider_id' => $use === 'embedding' ? $openai->id : $minimax->id, 'model' => $model, 'active' => true],
            );
        }

        RagCollection::firstOrCreate(
            ['key' => 'default'],
            [
                'name'                  => 'Colección por defecto',
                'embedding_provider_id' => $openai->id,
                'embedding_model'       => ProviderPresets::OPENAI_EMBEDDING,
                'dimension'             => 1536,
                'active'                => true,
            ],
        );

        McpServer::firstOrCreate(
            ['key' => 'question-bank'],
            ['name' => 'Banco de Preguntas', 'enabled' => true, 'api_key' => null],
        );
    }
}
