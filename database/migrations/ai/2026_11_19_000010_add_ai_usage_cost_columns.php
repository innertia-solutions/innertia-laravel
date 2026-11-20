<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia\Ai — usage cost accounting (additive and idempotent).
 *
 *   ai_usage_events + tenant_id, organization_id, feature, units (json), cost_usd
 *   ai_models       + price_input_per_mtok, price_output_per_mtok, price_per_audio_minute, price_per_mchar_tts (USD)
 *
 * Loaded by AiServiceProvider while the Ai feature is active. The ai tables themselves are created by
 * the product migration generated with `innertia:ai:install`: when they do not exist yet this migration
 * is a no-op (the install stub already carries these columns). Old rows stay valid (all columns nullable).
 * Backfill: tenant_id from the user's only tenant (user_contexts), when it can be determined.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage_events')) {
            $missing = fn (string $col) => ! Schema::hasColumn('ai_usage_events', $col);

            Schema::table('ai_usage_events', function (Blueprint $table) use ($missing) {
                if ($missing('tenant_id')) {
                    $table->string('tenant_id')->nullable()->index();
                }
                if ($missing('organization_id')) {
                    $table->string('organization_id')->nullable();
                }
                if ($missing('feature')) {
                    $table->string('feature')->nullable()->index();
                }
                if ($missing('units')) {
                    $table->json('units')->nullable();
                }
                if ($missing('cost_usd')) {
                    $table->decimal('cost_usd', 16, 8)->nullable();
                }
            });

            $this->backfillTenant();
        }

        if (Schema::hasTable('ai_models')) {
            foreach (['price_input_per_mtok', 'price_output_per_mtok', 'price_per_audio_minute', 'price_per_mchar_tts'] as $col) {
                if (! Schema::hasColumn('ai_models', $col)) {
                    Schema::table('ai_models', fn (Blueprint $table) => $table->decimal($col, 14, 6)->nullable());
                }
            }
        }
    }

    public function down(): void
    {
        // Additive columns on product-owned tables: intentionally kept on rollback.
    }

    /** Users that belong to exactly one tenant: their events get that tenant. */
    private function backfillTenant(): void
    {
        if (! Schema::hasTable('user_contexts') || ! Schema::hasColumn('user_contexts', 'tenant_id')) {
            return;
        }

        $single = DB::table('user_contexts')
            ->select('user_id')
            ->selectRaw('MIN(tenant_id) as tenant_id')
            ->whereNotNull('tenant_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(DISTINCT tenant_id) = 1')
            ->get();

        foreach ($single->chunk(500) as $chunk) {
            foreach ($chunk as $row) {
                DB::table('ai_usage_events')
                    ->whereNull('tenant_id')
                    ->where('user_id', (string) $row->user_id)
                    ->update(['tenant_id' => (string) $row->tenant_id]);
            }
        }
    }
};
