<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\DocChunk;
use Innertia\Ai\Models\RagCollection;
use Innertia\Ai\Rag\RagIndexer;
use Innertia\Ai\Rag\RagRetriever;

pest()->group('pgvector');

beforeEach(function () {
    // Point the default connection at the real pgvector test database.
    config()->set('database.connections.pgsql', [
        'driver'         => 'pgsql',
        'host'           => env('AI_PG_TEST_HOST', '127.0.0.1'),
        'port'           => (int) env('AI_PG_TEST_PORT', 5444),
        'database'       => env('AI_PG_TEST_DB', 'innertia_ai_testing'),
        'username'       => env('AI_PG_TEST_USER', 'innertia'),
        'password'       => env('AI_PG_TEST_PASSWORD', 'secret'),
        'charset'        => 'utf8',
        'prefix'         => '',
        'prefix_indexes' => true,
        'search_path'    => 'public',
        'sslmode'        => 'prefer',
    ]);
    config()->set('database.default', 'pgsql');

    try {
        DB::connection('pgsql')->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('pgvector test DB not reachable: ' . $e->getMessage());
    }

    // Generate + load the AI migration onto pgsql. Drop first for a clean slate.
    config()->set('innertia.ai.enabled', true);
    $dir = sys_get_temp_dir() . '/innertia-ai-pg-' . uniqid();
    File::ensureDirectoryExists($dir);
    $this->artisan('innertia:ai:install', ['--path' => $dir])->assertSuccessful();
    $this->migration = require glob($dir . '/*_create_ai_tables*.php')[0];
    try { $this->migration->down(); } catch (\Throwable $e) { /* tables may not exist yet */ }
    $this->migration->up();
    File::deleteDirectory($dir);

    // A deterministic `fake` embedding provider + a small-dimension collection.
    $provider = AiProvider::create([
        'key' => 'fake-emb', 'name' => 'Fake', 'type' => 'fake', 'active' => true,
    ]);
    RagCollection::create([
        'key' => 'test-col', 'name' => 'Test', 'embedding_provider_id' => $provider->id,
        'embedding_model' => 'fake', 'dimension' => 8, 'active' => true,
    ]);
});

afterEach(function () {
    if (isset($this->migration)) {
        try { $this->migration->down(); } catch (\Throwable $e) { /* ignore */ }
    }
});

it('indexes text into chunks with embeddings and records a done index run', function () {
    $run = (new RagIndexer)->ingestText('test-col', 'alpha beta gamma', ['source' => 'doc1']);

    $collectionId = RagCollection::where('key', 'test-col')->value('id');
    $chunks = DocChunk::where('collection_id', $collectionId)->get();

    expect($chunks)->not->toBeEmpty();
    expect($run->status)->toBe('done');
    expect($run->chunks_count)->toBe($chunks->count());
    // embedding column populated
    $raw = DB::table('ai_doc_chunks')->where('collection_id', $collectionId)->first();
    expect($raw->embedding)->not->toBeNull();
});

it('retrieves the nearest chunk by vector distance', function () {
    $indexer = new RagIndexer;
    $indexer->ingestText('test-col', 'alpha');
    $indexer->ingestText('test-col', 'bravo');
    $indexer->ingestText('test-col', 'charlie');

    $results = (new RagRetriever)->search('test-col', 'bravo', [], 3);

    expect($results)->not->toBeEmpty();
    expect($results[0]['content'])->toBe('bravo');
    expect($results[0]['distance'])->toBeLessThan(0.0001); // identical fake vectors → distance ~0
});

it('filters results by metadata equality', function () {
    $indexer = new RagIndexer;
    $indexer->ingestText('test-col', 'alpha', ['lang' => 'es']);
    $indexer->ingestText('test-col', 'alpha', ['lang' => 'en']);

    $results = (new RagRetriever)->search('test-col', 'alpha', ['lang' => 'en'], 5);

    expect($results)->not->toBeEmpty();
    foreach ($results as $r) {
        expect($r['metadata']['lang'])->toBe('en');
    }
});
