<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Tenant-level app enablement is no longer DB-driven.
 * Apps are defined in config('innertia.apps') and available to all tenants.
 * Per-tenant app restrictions can be handled at the product level if needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // no-op — kept to avoid breaking existing migration history
    }

    public function down(): void
    {
        // no-op
    }
};
