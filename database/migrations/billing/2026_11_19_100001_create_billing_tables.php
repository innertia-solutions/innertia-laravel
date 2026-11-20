<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia\Billing — plans, subscriptions per space, metered usage, statements, invoices, payments.
 *
 * Loaded only while the billing feature is active (innertia.billing.enabled + clients). Idempotent:
 * every table is created only when missing. Money: integers in the currency minor unit (CLP = pesos).
 * Period dates are local dates in innertia.billing.timezone (America/Santiago), both ends inclusive.
 * tenant_id is a string with the tenant primary key (same convention as HasTenant tables).
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

        $create('billing_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key')->unique();
            $t->string('name');
            $t->string('description')->nullable();
            $t->string('product')->nullable();
            $t->string('currency', 3)->default('CLP');
            $t->string('interval')->default('monthly');          // monthly | annual
            $t->bigInteger('base_price')->default(0);
            $t->unsignedInteger('included_seats')->default(0);
            $t->bigInteger('seat_price')->default(0);
            $t->unsignedSmallInteger('trial_days')->default(0);
            $t->decimal('tax_rate', 5, 2)->default(19);
            $t->json('limits')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        $create('billing_plan_meters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('plan_id')->index();
            $t->string('meter');
            $t->decimal('included_quantity', 18, 4)->default(0);  // cost-valued meters: amount in minor units
            $t->bigInteger('unit_price')->default(0);
            $t->decimal('markup_percent', 6, 2)->nullable();      // cost-valued meters only
            $t->timestamps();
            $t->unique(['plan_id', 'meter']);
        });

        $create('billing_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->unique();
            $t->uuid('plan_id')->index();
            $t->string('status')->default('active')->index();     // trialing|active|past_due|suspended|canceled
            $t->date('started_at');
            $t->date('trial_ends_at')->nullable();
            $t->date('current_period_start');
            $t->date('current_period_end');
            $t->string('payment_method')->default('invoice');     // automatic | invoice
            $t->json('overrides')->nullable();
            $t->uuid('pending_plan_id')->nullable();
            $t->boolean('suspended_manually')->default(false);
            $t->timestamp('suspended_at')->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->timestamps();
        });

        $create('billing_usage_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->index();
            $t->string('meter');
            $t->decimal('quantity', 20, 6);
            $t->bigInteger('cost')->nullable();                   // minor units, cost-valued meters
            $t->timestamp('occurred_at');
            $t->string('source_ref')->nullable();
            $t->json('meta')->nullable();
            $t->uuid('statement_id')->nullable()->index();
            $t->timestamp('created_at')->nullable();
            $t->unique(['meter', 'source_ref']);
            $t->index(['tenant_id', 'meter', 'occurred_at']);
        });

        $create('billing_adjustments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->index();
            $t->string('type');                                   // discount|surcharge|one_off|credit
            $t->bigInteger('amount')->nullable();
            $t->decimal('percent', 6, 2)->nullable();
            $t->boolean('recurring')->default(false);
            $t->date('starts_at')->nullable();
            $t->date('ends_at')->nullable();
            $t->string('reason');
            $t->string('created_by')->nullable();
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });

        $create('billing_statements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->index();
            $t->uuid('subscription_id')->index();
            $t->uuid('plan_id')->nullable();
            $t->date('period_start');
            $t->date('period_end');
            $t->string('currency', 3)->default('CLP');
            $t->decimal('tax_rate', 5, 2)->default(19);
            $t->bigInteger('subtotal')->default(0);
            $t->bigInteger('tax')->default(0);
            $t->bigInteger('total')->default(0);
            $t->uuid('invoice_id')->nullable()->index();
            $t->string('trace_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'period_start']);
        });

        $create('billing_statement_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('statement_id')->index();
            $t->string('kind');                                   // base|seats|meter|adjustment|late_fee|late_usage
            $t->string('description');
            $t->string('meter')->nullable();
            $t->decimal('quantity', 20, 6)->default(1);
            $t->bigInteger('unit_price')->default(0);
            $t->bigInteger('amount')->default(0);
            $t->json('meta')->nullable();
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });

        $create('billing_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_id')->index();
            $t->string('tenant_id')->nullable()->index();         // null = consolidated
            $t->unsignedBigInteger('number')->nullable()->unique(); // assigned when issued
            $t->date('period_start');
            $t->date('period_end');
            $t->string('currency', 3)->default('CLP');
            $t->json('billing_data')->nullable();
            $t->bigInteger('subtotal')->default(0);
            $t->bigInteger('tax')->default(0);
            $t->bigInteger('total')->default(0);
            $t->bigInteger('balance')->default(0);
            $t->string('status')->default('draft')->index();      // draft|issued|paid|overdue|void
            $t->string('payment_method')->default('invoice');
            $t->string('folio')->nullable();
            $t->string('pdf_file_id')->nullable();
            $t->text('payment_link')->nullable();
            $t->string('charge_id')->nullable()->index();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->uuid('replaces_invoice_id')->nullable();
            $t->string('void_reason')->nullable();
            $t->string('trace_id')->nullable();
            $t->timestamps();
        });

        $create('billing_invoice_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('invoice_id')->index();
            $t->string('event');
            $t->string('user_id')->nullable();
            $t->json('data')->nullable();
            $t->string('trace_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $create('billing_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_id')->index();
            $t->bigInteger('amount');
            $t->string('method');                                 // transfer|link|manual|credit
            $t->string('reference')->nullable();
            $t->timestamp('received_at');
            $t->string('charge_id')->nullable()->unique();        // Innertia\Payments charge (idempotency)
            $t->bigInteger('unallocated_amount')->default(0);
            $t->string('created_by')->nullable();
            $t->string('trace_id')->nullable();
            $t->timestamps();
        });

        $create('billing_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('payment_id')->index();
            $t->uuid('invoice_id')->index();
            $t->bigInteger('amount');
            $t->string('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach ([
            'billing_allocations', 'billing_payments', 'billing_invoice_events', 'billing_invoices',
            'billing_statement_lines', 'billing_statements', 'billing_adjustments', 'billing_usage_records',
            'billing_subscriptions', 'billing_plan_meters', 'billing_plans',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
