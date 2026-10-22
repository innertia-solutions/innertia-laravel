<?php

namespace Innertia\Ai\Database\Seeders;

use Illuminate\Database\Seeder;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiModel;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\McpServer;
use Innertia\Ai\Models\RagCollection;

/**
 * Defaults del módulo de IA: proveedor MiniMax (openai-compatible, sin key),
 * modelos chat/visión/embedding, asignaciones por uso, colección RAG `default`
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

        $models = [
            ['key' => 'MiniMax-M2',    'name' => 'MiniMax M2',          'capabilities' => ['chat']],
            ['key' => 'abab6.5v-chat', 'name' => 'abab6.5v (visión)',   'capabilities' => ['vision', 'chat']],
            ['key' => 'embo-01',       'name' => 'embo-01 (embedding)', 'capabilities' => ['embedding']],
        ];
        foreach ($models as $model) {
            AiModel::firstOrCreate(
                ['provider_id' => $minimax->id, 'key' => $model['key']],
                ['name' => $model['name'], 'capabilities' => $model['capabilities'], 'active' => true],
            );
        }

        $assignments = [
            'chat'      => 'MiniMax-M2',
            'assistant' => 'MiniMax-M2',
            'vision'    => 'abab6.5v-chat',
            'embedding' => 'embo-01',
        ];
        foreach ($assignments as $use => $model) {
            AiAssignment::firstOrCreate(
                ['use' => $use],
                ['provider_id' => $minimax->id, 'model' => $model, 'active' => true],
            );
        }

        RagCollection::firstOrCreate(
            ['key' => 'default'],
            [
                'name'                  => 'Colección por defecto',
                'embedding_provider_id' => $minimax->id,
                'embedding_model'       => 'embo-01',
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
