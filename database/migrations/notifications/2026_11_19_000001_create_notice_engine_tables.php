<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications and branded emails engine (idempotent: safe on products that already
 * have some of these tables/columns, and re-runnable).
 *
 *   notification_preferences — user × notice type × channel (bell|mail) → enabled
 *   notice_states            — open notices per (tenant, type, subject, user): debounce,
 *                              grouping ("3 llamadas perdidas") and resolve()
 *   invitation_tokens        — account invitation links (own broker, TTL in days)
 *   email_logs (+columns)    — type, tenant_id and trace_id for every email
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // string: products use bigint or uuid users.id
                $table->string('user_id')->index();
                $table->string('type', 120);
                $table->string('channel', 20);
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'type', 'channel']);
            });
        }

        if (! Schema::hasTable('notice_states')) {
            Schema::create('notice_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tenant_id')->nullable()->index();
                $table->string('type', 120);
                $table->string('subject_key', 191)->default('');
                $table->string('user_id');
                $table->string('notification_id')->nullable();
                $table->unsignedInteger('count')->default(1);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['type', 'subject_key', 'user_id']);
            });
        }

        if (! Schema::hasTable('invitation_tokens')) {
            Schema::create('invitation_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('user_id')->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('expires_at')->nullable();
            });
        }

        if (Schema::hasTable('email_logs')) {
            foreach (['type' => 120, 'tenant_id' => 191, 'trace_id' => 64] as $column => $length) {
                if (! Schema::hasColumn('email_logs', $column)) {
                    Schema::table('email_logs', function (Blueprint $table) use ($column, $length) {
                        $table->string($column, $length)->nullable()->index();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_tokens');
        Schema::dropIfExists('notice_states');
        Schema::dropIfExists('notification_preferences');

        if (Schema::hasTable('email_logs')) {
            foreach (['type', 'tenant_id', 'trace_id'] as $column) {
                if (Schema::hasColumn('email_logs', $column)) {
                    Schema::table('email_logs', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
