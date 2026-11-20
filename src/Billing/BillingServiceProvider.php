<?php

namespace Innertia\Billing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Innertia\Billing\Contracts\PaymentLinkProvider;
use Innertia\Billing\Contracts\SeatCounter;
use Innertia\Billing\Support\BillingClock;
use Innertia\Billing\Support\BillingNotices;
use Innertia\Billing\Support\DefaultSeatCounter;
use Innertia\Billing\Support\NullPaymentLinkProvider;
use Innertia\Billing\Support\PaymentsLinkProvider;

/**
 * Wiring of Innertia\Billing. Everything below boot() only happens while BillingFeature is active:
 * migrations, schedule (close + dunning, billing time zone), AI cost and payment-link listeners,
 * notice types and the `billing.enforce` middleware alias. Products bind their own SeatCounter /
 * PaymentLinkProvider in their providers (bindIf here keeps theirs).
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(SeatCounter::class, DefaultSeatCounter::class);
        $this->app->bindIf(PaymentLinkProvider::class, function () {
            $provider = config('innertia.billing.payment_provider');

            return filled($provider) && config('innertia.payments.enabled')
                ? new PaymentsLinkProvider((string) $provider)
                : new NullPaymentLinkProvider;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Registered always: the commands check the feature themselves.
            $this->commands([Console\CloseBillingPeriodsCommand::class, Console\RunDunningCommand::class]);
        }

        if (! BillingFeature::isActive()) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/billing');
        $this->app['router']->aliasMiddleware('billing.enforce', Middleware\EnforceSubscription::class);

        Event::listen(\Innertia\Ai\Events\AiUsageRecorded::class, Listeners\RecordAiCost::class);
        Event::listen(\Innertia\Payments\Events\PaymentPaid::class, Listeners\ApplyLinkPayment::class);

        \Innertia\Notifications\NoticeTypes::register(...BillingNotices::types());

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule));
    }

    public function schedule(Schedule $schedule): void
    {
        $tz = BillingClock::timezone();

        $schedule->command(Console\CloseBillingPeriodsCommand::class)
            ->dailyAt((string) config('innertia.billing.close_at', '00:30'))
            ->timezone($tz)
            ->name('innertia:billing:close')
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->command(Console\RunDunningCommand::class)
            ->dailyAt((string) config('innertia.billing.dunning_at', '08:00'))
            ->timezone($tz)
            ->name('innertia:billing:dunning')
            ->withoutOverlapping(60)
            ->onOneServer();
    }
}
