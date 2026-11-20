<?php

namespace Innertia\Billing\Console;

use Illuminate\Console\Command;
use Innertia\Billing\BillingFeature;
use Innertia\Billing\UseCases\SendPaymentReminders;

class SendPaymentRemindersCommand extends Command
{
    protected $signature = 'innertia:billing:reminders {--sync : Run now instead of queueing it} {--date= : Local date (Y-m-d) to evaluate}';

    protected $description = 'Send payment reminders (before due, due day, overdue with escalation).';

    public function handle(): int
    {
        if (! BillingFeature::isActive()) {
            $this->warn('Billing is not active (innertia.billing.enabled + clients).');

            return self::SUCCESS;
        }

        $useCase = new SendPaymentReminders($this->option('date') ?: null);
        if ($this->option('sync')) {
            $result = $useCase->execute();
            $this->info("Reminders sent: {$result['sent']} · escalated: {$result['escalated']} · skipped: {$result['skipped']}");
        } else {
            $useCase->onQueue((string) config('innertia.billing.queue', 'use-cases'));
            $this->info('Payment reminders queued.');
        }

        return self::SUCCESS;
    }
}
