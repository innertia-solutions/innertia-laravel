<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia\Billing — stage 3 (collections): credit notes and client-level discounts on adjustments,
 * void/reissue trail on invoices, late fees, client terms (late fee basis/cap/exemption, reminders),
 * bank statement reconciliation, payment reminders and the billing activity log.
 *
 * Loaded only while the billing feature is active. Idempotent: tables are created only when missing
 * and columns are added only when missing (stage 2 databases upgrade in place).
 */
return new class extends Migration
{
    public function up(): void
    {
        $create = function (string $table, \Closure $definition) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        };
        $add = function (string $table, string $column, \Closure $definition) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
                Schema::table($table, $definition);
            }
        };

        // ── Adjustments: client-level discounts and credit notes ───────────────
        $add('billing_adjustments', 'client_id', fn (Blueprint $t) => $t->uuid('client_id')->nullable()->index());
        $add('billing_adjustments', 'invoice_id', fn (Blueprint $t) => $t->uuid('invoice_id')->nullable()->index());
        $add('billing_adjustments', 'payment_id', fn (Blueprint $t) => $t->uuid('payment_id')->nullable());
        $add('billing_adjustments', 'number', fn (Blueprint $t) => $t->unsignedBigInteger('number')->nullable()->unique());
        $add('billing_adjustments', 'target', fn (Blueprint $t) => $t->string('target')->nullable());     // next_period|invoice|balance
        $add('billing_adjustments', 'canceled_at', fn (Blueprint $t) => $t->timestamp('canceled_at')->nullable());
        $add('billing_adjustments', 'canceled_by', fn (Blueprint $t) => $t->string('canceled_by')->nullable());
        $add('billing_adjustments', 'cancel_reason', fn (Blueprint $t) => $t->string('cancel_reason')->nullable());
        $add('billing_adjustments', 'trace_id', fn (Blueprint $t) => $t->string('trace_id')->nullable());
        // Client-level adjustments have no space.
        if (Schema::hasTable('billing_adjustments')) {
            Schema::table('billing_adjustments', fn (Blueprint $t) => $t->string('tenant_id')->nullable()->change());
        }

        // ── Invoices: void / reissue trail and late fee waiver ─────────────────
        $add('billing_invoices', 'voided_at', fn (Blueprint $t) => $t->timestamp('voided_at')->nullable());
        $add('billing_invoices', 'voided_by', fn (Blueprint $t) => $t->string('voided_by')->nullable());
        $add('billing_invoices', 'replaced_by_invoice_id', fn (Blueprint $t) => $t->uuid('replaced_by_invoice_id')->nullable());
        $add('billing_invoices', 'late_fees_waived_at', fn (Blueprint $t) => $t->timestamp('late_fees_waived_at')->nullable());

        // ── Payments: origin in a bank statement (idempotent reconciliation) ────
        $add('billing_payments', 'bank_transaction_id', fn (Blueprint $t) => $t->uuid('bank_transaction_id')->nullable()->unique());

        $create('billing_client_terms', function (Blueprint $t) {
            $t->uuid('client_id')->primary();
            $t->string('late_fee_basis')->default('monthly');          // monthly | daily
            $t->decimal('late_fee_cap_percent', 6, 2)->nullable();      // cap over the invoice total
            $t->boolean('late_fee_exempt')->default(false);
            $t->boolean('reminders_enabled')->default(true);
            $t->string('updated_by')->nullable();
            $t->timestamps();
        });

        $create('billing_late_fees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('invoice_id')->index();                            // the overdue invoice
            $t->string('tenant_id')->index();                           // the space charged
            $t->uuid('statement_id')->nullable()->index();              // where it was billed
            $t->date('from_date');
            $t->date('to_date');
            $t->unsignedInteger('days');
            $t->bigInteger('base');
            $t->decimal('rate', 10, 6);                                 // percent per day
            $t->bigInteger('amount');
            $t->timestamp('waived_at')->nullable();
            $t->string('waived_by')->nullable();
            $t->string('waive_reason')->nullable();
            $t->string('trace_id')->nullable();
            $t->timestamps();
        });

        $create('billing_bank_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('file_name');
            $t->string('file_hash', 64)->unique();                      // same file → same import
            $t->string('format', 8);                                    // csv | xlsx
            $t->string('bank')->nullable();
            $t->json('mapping');
            $t->json('options')->nullable();
            $t->longText('content')->nullable();                        // base64, cleared once processed
            $t->string('status')->default('pending')->index();          // pending|processing|done|failed
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_imported')->default(0);
            $t->unsignedInteger('rows_duplicated')->default(0);
            $t->unsignedInteger('rows_skipped')->default(0);
            $t->unsignedInteger('matched')->default(0);
            $t->unsignedInteger('suggested')->default(0);
            $t->text('error')->nullable();
            $t->string('created_by')->nullable();
            $t->string('trace_id')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
        });

        $create('billing_bank_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('import_id')->index();
            $t->string('row_hash', 64)->unique();                       // overlapping statements never duplicate
            $t->date('posted_at');
            $t->bigInteger('amount');
            $t->text('description')->nullable();
            $t->string('reference')->nullable();
            $t->string('tax_id')->nullable();
            $t->string('counterpart_name')->nullable();
            $t->json('raw')->nullable();
            $t->string('status')->default('unmatched')->index();        // unmatched|suggested|matched|ignored
            $t->unsignedSmallInteger('score')->nullable();
            $t->json('suggestions')->nullable();
            $t->uuid('client_id')->nullable()->index();
            $t->uuid('payment_id')->nullable();
            $t->string('matched_by')->nullable();                       // user id | 'auto'
            $t->timestamp('matched_at')->nullable();
            $t->string('ignore_reason')->nullable();
            $t->string('trace_id')->nullable();
            $t->timestamps();
        });

        $create('billing_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('invoice_id')->index();
            $t->string('kind');                                         // before | due | overdue
            $t->unsignedSmallInteger('sequence')->default(0);
            $t->date('sent_on');
            $t->string('email')->nullable();
            $t->boolean('escalated')->default(false);
            $t->string('trace_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['invoice_id', 'kind', 'sequence']);
        });

        $create('billing_activity', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_id')->nullable()->index();
            $t->string('tenant_id')->nullable()->index();
            $t->string('subject_type');                                 // adjustment|credit_note|invoice|terms|bank_import|bank_transaction|late_fee
            $t->string('subject_id')->nullable();
            $t->string('action');
            $t->string('user_id')->nullable();
            $t->json('data')->nullable();
            $t->string('trace_id')->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['billing_activity', 'billing_reminders', 'billing_bank_transactions', 'billing_bank_imports', 'billing_late_fees', 'billing_client_terms'] as $table) {
            Schema::dropIfExists($table);
        }

        $drop = function (string $table, array $columns) {
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($present !== [] && Schema::hasTable($table)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        };
        if (Schema::hasTable('billing_payments') && Schema::hasColumn('billing_payments', 'bank_transaction_id')) {
            Schema::table('billing_payments', fn (Blueprint $t) => $t->dropUnique(['bank_transaction_id']));
        }
        if (Schema::hasTable('billing_adjustments') && Schema::hasColumn('billing_adjustments', 'number')) {
            Schema::table('billing_adjustments', fn (Blueprint $t) => $t->dropUnique(['number']));
        }
        $drop('billing_payments', ['bank_transaction_id']);
        $drop('billing_invoices', ['voided_at', 'voided_by', 'replaced_by_invoice_id', 'late_fees_waived_at']);
        $drop('billing_adjustments', ['client_id', 'invoice_id', 'payment_id', 'number', 'target', 'canceled_at', 'canceled_by', 'cancel_reason', 'trace_id']);
    }
};
