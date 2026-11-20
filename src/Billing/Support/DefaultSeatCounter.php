<?php

namespace Innertia\Billing\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innertia\Billing\Contracts\SeatCounter;

/** Default: distinct users with a context row in the space (products bind their own SeatCounter). */
class DefaultSeatCounter implements SeatCounter
{
    public function count(string $tenantId): int
    {
        if (! Schema::hasTable('user_contexts') || ! Schema::hasColumn('user_contexts', 'tenant_id')) {
            return 0;
        }

        return (int) DB::table('user_contexts')->where('tenant_id', $tenantId)->distinct()->count('user_id');
    }
}
