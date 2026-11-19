<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public API + webhooks v2 (idempotent and additive: re-runnable, never touches existing data).
 *
 *   webhooks (+columns)     — sede, encrypted Standard Webhooks secret (+ previous one for 24 h rotation),
 *                             granted scopes, health (failing_since / disabled_at), creator
 *   webhook_logs (+columns) — webhook-id, status, ms, error, request headers, test flag, trace_id, next retry
 *   api_keys (+columns)     — indexed readable prefix (pk_live_…), sede, last IP, creator, rotation link
 *   api_request_logs        — requests made with an API key (never the key: only its prefix)
 *   api_idempotency_keys    — Idempotency-Key replays for writes (24 h)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhooks')) {
            $this->addColumns('webhooks', [
                'organization_id'            => fn (Blueprint $t) => $t->string('organization_id')->nullable()->index(),
                'signing_secret'             => fn (Blueprint $t) => $t->text('signing_secret')->nullable(),
                'previous_secret'            => fn (Blueprint $t) => $t->text('previous_secret')->nullable(),
                'previous_secret_expires_at' => fn (Blueprint $t) => $t->timestamp('previous_secret_expires_at')->nullable(),
                'secret_hint'                => fn (Blueprint $t) => $t->string('secret_hint', 16)->nullable(),
                'scopes'                     => fn (Blueprint $t) => $t->json('scopes')->nullable(),
                'created_by'                 => fn (Blueprint $t) => $t->string('created_by')->nullable(),
                'disabled_at'                => fn (Blueprint $t) => $t->timestamp('disabled_at')->nullable(),
                'disabled_reason'            => fn (Blueprint $t) => $t->string('disabled_reason')->nullable(),
                'failing_since'              => fn (Blueprint $t) => $t->timestamp('failing_since')->nullable(),
                'last_success_at'            => fn (Blueprint $t) => $t->timestamp('last_success_at')->nullable(),
                'last_failure_at'            => fn (Blueprint $t) => $t->timestamp('last_failure_at')->nullable(),
            ]);
        }

        if (Schema::hasTable('webhook_logs')) {
            $this->addColumns('webhook_logs', [
                'message_id'      => fn (Blueprint $t) => $t->string('message_id', 64)->nullable()->index(),
                'status'          => fn (Blueprint $t) => $t->string('status', 16)->nullable(),
                'duration_ms'     => fn (Blueprint $t) => $t->unsignedInteger('duration_ms')->nullable(),
                'error'           => fn (Blueprint $t) => $t->text('error')->nullable(),
                'request_headers' => fn (Blueprint $t) => $t->json('request_headers')->nullable(),
                'is_test'         => fn (Blueprint $t) => $t->boolean('is_test')->default(false),
                'trace_id'        => fn (Blueprint $t) => $t->string('trace_id', 64)->nullable(),
                'next_retry_at'   => fn (Blueprint $t) => $t->timestamp('next_retry_at')->nullable(),
            ]);
        }

        if (Schema::hasTable('api_keys')) {
            $this->addColumns('api_keys', [
                'prefix'          => fn (Blueprint $t) => $t->string('prefix', 24)->nullable()->index(),
                'organization_id' => fn (Blueprint $t) => $t->string('organization_id')->nullable(),
                'last_used_ip'    => fn (Blueprint $t) => $t->string('last_used_ip', 45)->nullable(),
                'created_by'      => fn (Blueprint $t) => $t->string('created_by')->nullable(),
                'rotated_to_id'   => fn (Blueprint $t) => $t->uuid('rotated_to_id')->nullable(),
                'rotated_at'      => fn (Blueprint $t) => $t->timestamp('rotated_at')->nullable(),
            ]);
        }

        if (! Schema::hasTable('api_request_logs')) {
            Schema::create('api_request_logs', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id')->nullable()->index();
                $table->uuid('api_key_id')->nullable()->index();
                $table->string('key_prefix', 24)->nullable();
                $table->string('method', 8);
                $table->string('path', 255);
                $table->string('route', 255)->nullable();
                $table->unsignedSmallInteger('status');
                $table->unsignedInteger('duration_ms')->default(0);
                $table->string('ip', 45)->nullable();
                $table->string('trace_id', 64)->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (! Schema::hasTable('api_idempotency_keys')) {
            Schema::create('api_idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id')->nullable();
                $table->uuid('api_key_id');
                $table->string('idempotency_key', 191);
                $table->string('method', 8);
                $table->string('path', 255);
                $table->string('request_hash', 64);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->longText('response_body')->nullable();
                $table->json('response_headers')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();

                $table->unique(['api_key_id', 'idempotency_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_request_logs');
        // Columns added to webhooks / webhook_logs / api_keys are left in place (additive, harmless).
    }

    /** @param array<string, callable(Blueprint): mixed> $columns */
    private function addColumns(string $table, array $columns): void
    {
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $define($t));
            }
        }
    }
};
