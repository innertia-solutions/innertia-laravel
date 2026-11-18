<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('business_activity')->nullable();
            $table->string('address')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('invoicing_mode')->default('consolidated');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->unsignedSmallInteger('grace_days')->default(15);
            $table->decimal('late_fee_percent', 5, 2)->default(0);
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->index();
            $table->string('environment')->default('production')->index();
            $table->json('billing_profile')->nullable();
        });

        // Backfill: each existing tenant becomes the only space of its own client.
        $now = now();
        foreach (DB::table('tenants')->orderBy('id')->get(['id', 'key', 'name', 'status']) as $tenant) {
            $clientId = (string) Str::uuid();
            DB::table('clients')->insert([
                'id'         => $clientId,
                'key'        => $tenant->key,
                'name'       => $tenant->name,
                'status'     => $tenant->status === 'inactive' ? 'inactive' : 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('tenants')->where('id', $tenant->id)->update(['client_id' => $clientId]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
            $table->dropIndex(['environment']);
            $table->dropColumn(['client_id', 'environment', 'billing_profile']);
        });
        Schema::dropIfExists('clients');
    }
};
