---
name: innertia-ai
description: Módulo de IA genérico y DB-backed (Innertia\Ai). Proveedores/modelos/asignación-por-uso, embeddings, RAG multi-colección (pgvector), asistente laravel/ai, registro MCP y defaults. Opt-in.
---

# Innertia Ai

Módulo de IA **genérico, DB-backed y reutilizable**: proveedores y modelos, asignación de proveedor por uso (chat/visión/embedding/asistente), embeddings multi-adaptador, RAG multi-colección sobre pgvector, asistente genérico con `laravel/ai`, registro + auth de servidores MCP, y defaults sembrados. Opt-in al estilo de Tags/Organizations.

Toda la configuración vive en **base de datos** (keys cifradas), no en `Settings` ni en un `config/ai.php` del producto. La dimensión del embedding vive por colección.

## Cuándo usar

- El producto necesita IA: chat/asistente, OCR de imágenes (visión), embeddings, RAG (búsqueda semántica), y/o exponer tools por MCP.
- Querés distinto proveedor/modelo por uso (ej. chat con MiniMax-M2, visión con abab6.5v, embeddings con embo-01) sin hardcodear.
- Querés estadísticas de consumo (tokens por uso/proveedor).

NO uses este módulo para lógica de dominio del producto — las **tools** MCP y la **UI/consola** las aporta el producto; la librería da el motor.

## Habilitar

1. `.env`: `INNERTIA_AI_ENABLED=true`
2. `php artisan innertia:ai:install` → genera la migración (10 tablas).
3. `php artisan migrate`.
4. `php artisan innertia:ai:seed` → siembra defaults (proveedor MiniMax sin key, modelos, asignaciones chat/assistant/vision/embedding, colección RAG `default`, server MCP `question-bank`).
5. Cargar credenciales reales (la `api_key` del proveedor) por UI/seed del producto — el default queda con key vacía.

La feature gatea su gate único: `\Innertia\Ai\AiFeature::isActive()` = `config('innertia.ai.enabled')`.

**Requisitos:** Laravel 12+/PHP 8.3 (lo exige `laravel/ai`). pgvector para RAG real (en pgsql); en sqlite la columna `embedding` cae a `text` (la suite corre, pero el retriever `<->` requiere pgsql).

## Modelo de datos (10 tablas)

`ai_providers` (key, name, type, base_url, api_key **cifrada**, active, meta) · `ai_models` (provider_id, key, capabilities) · `ai_assignments` (**use** único ∈ chat|vision|embedding|assistant, provider_id, model) · `ai_usage_events` (provider_id, model, use, prompt/completion/total_tokens, user_id) · `ai_rag_collections` (key, embedding_provider_id, embedding_model, dimension) · `ai_doc_chunks` (collection_id, content, metadata, token_count, **embedding** driver-aware vector/text) · `ai_conversations` / `ai_messages` · `ai_mcp_servers` (key, enabled, api_key **cifrada**) · `ai_index_runs` (auditoría de indexado).

Models Eloquent en `Innertia\Ai\Models\*` (HasUuids; `api_key` con cast `encrypted`).

## Facade `Ai` (`Innertia\Ai\Facades\Ai`)

```php
use Innertia\Ai\Facades\Ai;

Ai::enabled();                 // bool: hay proveedor+key para 'chat'
$model = Ai::configureFor('chat');   // resuelve la asignación del uso, carga la config
                                     // ai.providers.openai-compatible.{url,key} de laravel/ai
                                     // en runtime y devuelve el modelo (o null si no hay asignación)
Ai::embedder('default');       // EmbeddingProvider para la colección (o el uso 'embedding' global)
Ai::usage()->record('chat', $providerId, $model, $promptTokens, $completionTokens, $userId);
Ai::usage()->totalTokens('chat');    // agregado de tokens por uso (o todos)
```

**Multi-proveedor por uso:** `ai_assignments` mapea cada `use` a provider+model; `ProviderResolver::for($use)` resuelve. Así chat/visión/embedding/asistente pueden usar proveedores distintos.

## Embeddings

Contrato `Innertia\Ai\Embeddings\EmbeddingProvider` (`embedDocuments`, `embedQuery`, `dimension`). Adaptadores por `type` del provider:
- `minimax` / `openai-compatible` → `MiniMaxEmbeddingProvider` (`POST /embeddings {model,texts,type}` → `{vectors}`)
- `openai` → `OpenAiEmbeddingProvider` (`{model,input}` → `{data[].embedding}`)
- `fake` → `FakeEmbeddingProvider` (determinista, sin red — dev/tests)

`Ai::embedder($collectionKey)` elige el adaptador por el proveedor de la colección (o el uso `embedding`) y lo dimensiona según la colección.

## RAG (multi-colección, pgvector)

```php
use Innertia\Ai\Rag\RagIndexer;
use Innertia\Ai\Rag\RagRetriever;

$run = (new RagIndexer)->ingestText('default', $texto, ['source' => 'doc-42']); // chunk → embed → insert
$hits = (new RagRetriever)->search('default', 'mi consulta', ['source' => 'doc-42'], k: 5);
// $hits: [['content' => ..., 'metadata' => [...], 'distance' => float], ...]
```

`RagIndexer` es **driver-aware**: en pgsql inserta `embedding` con cast `::vector`; en sqlite guarda el literal como texto (fallback). `RagRetriever::search` usa la distancia pgvector `<->` (requiere pgsql; filtra por igualdad sobre claves de `metadata`). Cada indexado registra un `IndexRun`.

Convertir PDF/imagen → texto es responsabilidad del producto (o `VisionOcr` para imágenes); `RagIndexer::ingestText` es el núcleo.

## Asistente (laravel/ai)

```php
use Innertia\Ai\Assistant\AssistantRunner;

$result = (new AssistantRunner)->run(
    user: $request->user(),
    text: 'Creá una pregunta de fracciones',
    imageDataUrls: $imagenes,     // visión opcional (OCR vía el uso 'vision')
    tools: $misTools,             // laravel/ai Tool[] que aporta el producto
    systemPrompt: 'Sos un asistente...',
    conversationId: $id,          // opcional, continúa una conversación
);
// ['conversation_id' => ..., 'reply' => ..., 'usage' => ['prompt_tokens' => .., 'completion_tokens' => ..]]
```

El runner: OCR de imágenes (`VisionOcr`) → resuelve modelo (`assistant`, cae a `chat`) → carga historial → persiste `ai_conversations`/`ai_messages` → invoca el modelo → loguea uso.

**Seam testeable:** el runner depende de `Innertia\Ai\Assistant\Contracts\AssistantModel`. Producción = `LaravelAiModel` (corre un `GenericAgent` de laravel/ai contra `openai-compatible`); en tests se inyecta un fake sin red. `GenericAgent` implementa los contratos `Agent`/`HasTools`/`Conversational` de laravel/ai (instrucciones + tools + historial por constructor).

## MCP

La librería gestiona **registro + API key** de servidores MCP; las **tools/clases de server** las aporta el producto (en su `routes/ai.php`).

```php
use Innertia\Ai\Mcp\McpRegistry;
use Innertia\Ai\Mcp\EnsureMcpKey;

(new McpRegistry)->register('question-bank', 'Banco de Preguntas');
$token = (new McpRegistry)->generateKey('question-bank');   // 'mcp_...' (cifrada, rota al regenerar)

// Gatear la ruta MCP del producto por Bearer:
Route::post('/mcp/question-bank', QuestionBankServer::class)
    ->middleware('ensure.mcp.key:question-bank');   // alias registrado por AiServiceProvider
// 404 server inexistente/deshabilitado · 503 sin api_key · 401 Bearer ausente/incorrecto
```

## Cómo consume un producto

1. `INNERTIA_AI_ENABLED=true` + `innertia:ai:install` + `migrate` + `innertia:ai:seed`.
2. Cargar la `api_key` del proveedor (UI/seed del producto).
3. Aportar **tools** (contrato `laravel/ai\Contracts\Tool`) + su **server MCP** (en `routes/ai.php`, gateado por `ensure.mcp.key:<serverKey>`).
4. Usar `Ai::`, `AssistantRunner`, `RagIndexer`/`RagRetriever` con sus tools. La UI/consola es del producto.

## Comandos

```bash
innertia:ai:install [--path=] [--force]   # genera la migración (guard: AiFeature::isActive())
innertia:ai:seed                          # siembra defaults (idempotente)
```
