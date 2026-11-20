<?php

namespace Innertia\Billing\Console;

use Illuminate\Console\Command;
use Innertia\Billing\BillingFeature;
use Innertia\Billing\UseCases\CloseBillingPeriods;

class CloseBillingPeriodsCommand extends Command
{
    protected $signature = 'innertia:billing:close {--date= : Local date to close as (YYYY-MM-DD, default today in the billing time zone)}';

    protected $description = 'Close finished billing periods: statements, draft invoices, period advance.';

    public function handle(): int
    {
        if (! BillingFeature::isActive()) {
            $this->warn('Billing is not active (innertia.billing.enabled + clients).');

            return self::SUCCESS;
        }

        $result = (new CloseBillingPeriods($this->option('date') ?: null))->execute();
        $this->info("Subscriptions: {$result['subscriptions']} · statements: {$result['statements']} · failed: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
