<?php

namespace Innertia\Billing\Console;

use Illuminate\Console\Command;
use Innertia\Billing\BillingFeature;
use Innertia\Billing\UseCases\RunDunning;

class RunDunningCommand extends Command
{
    protected $signature = 'innertia:billing:dunning';

    protected $description = 'Mark overdue invoices and move subscriptions to past_due / suspended / active.';

    public function handle(): int
    {
        if (! BillingFeature::isActive()) {
            $this->warn('Billing is not active (innertia.billing.enabled + clients).');

            return self::SUCCESS;
        }

        $result = (new RunDunning)->execute();
        $this->info("Overdue invoices: {$result['overdue']} · subscriptions changed: {$result['changed']}");

        return self::SUCCESS;
    }
}
