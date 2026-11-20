<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Support\BillingSettings;
use Innertia\Billing\UseCases\CloseBillingPeriods;
use Innertia\Billing\UseCases\RunDunning;
use Innertia\Saas\Models\Client;

/** Platform: totals per client (MRR, open balance, overdue), settings and manual runs. */
class OverviewController extends Controller
{
    /** GET billing/clients/totals[?client_id=] */
    public function clients(Request $request): JsonResponse
    {
        $request->validate(['client_id' => 'nullable|uuid']);

        $clients = Client::query()
            ->with('tenants')
            ->when($request->input('client_id'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')->get();

        $tenantIds = $clients->flatMap(fn ($c) => $c->tenants->pluck('id'))->map(fn ($id) => (string) $id)->all();
        $subscriptions = Subscription::query()->with('plan')->whereIn('tenant_id', $tenantIds)->get()->keyBy('tenant_id');
        $open = Invoice::query()->whereIn('client_id', $clients->pluck('id'))
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])
            ->get(['client_id', 'status', 'balance'])->groupBy('client_id');
        $drafts = Invoice::query()->whereIn('client_id', $clients->pluck('id'))->where('status', InvoiceStatus::Draft->value)
            ->selectRaw('client_id, COUNT(*) as n')->groupBy('client_id')->pluck('n', 'client_id');

        $data = $clients->map(function (Client $client) use ($subscriptions, $open, $drafts) {
            $spaces = $client->tenants->map(function ($t) use ($subscriptions) {
                $s = $subscriptions->get((string) $t->id);
                $billable = $s && ! in_array($s->status, [SubscriptionStatus::Canceled, SubscriptionStatus::Trialing], true);

                return [
                    'key'          => $t->key,
                    'name'         => $t->name,
                    'environment'  => $t->environment?->value,
                    'subscription' => $s ? ['status' => $s->status->value, 'status_label' => $s->status->label(), 'plan' => $s->plan?->name, 'payment_method' => $s->payment_method->value] : null,
                    'mrr'          => $billable && $s->plan ? $s->plan->monthlyBase($s->overrides) : 0,
                ];
            })->values();

            $invoices = $open->get($client->id, collect());

            return [
                'client_id'     => $client->id,
                'name'          => $client->name,
                'invoicing_mode'=> $client->invoicing_mode?->value,
                'mrr'           => (int) $spaces->sum('mrr'),
                'balance'       => (int) $invoices->sum('balance'),
                'overdue'       => (int) $invoices->where('status', InvoiceStatus::Overdue)->sum('balance'),
                'drafts'        => (int) ($drafts[$client->id] ?? 0),
                'spaces'        => $spaces,
            ];
        })->values();

        return response()->json([
            'data'   => $data,
            'totals' => ['mrr' => (int) $data->sum('mrr'), 'balance' => (int) $data->sum('balance'), 'overdue' => (int) $data->sum('overdue')],
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(BillingSettings::all());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'usd_clp_rate'          => 'sometimes|nullable|numeric|min:1|max:100000',
            'transfer_instructions' => 'sometimes|nullable|string|max:1000',
            'issuer_name'           => 'sometimes|nullable|string|max:150',
            'issuer_tax_id'         => 'sometimes|nullable|string|max:20',
            'issuer_address'        => 'sometimes|nullable|string|max:250',
            'reminders_enabled'           => 'sometimes|nullable|boolean',
            'reminder_days_before'        => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'],
            'reminder_on_due'             => 'sometimes|nullable|boolean',
            'reminder_overdue_every_days' => 'sometimes|nullable|integer|min:1|max:90',
            'reminder_overdue_max'        => 'sometimes|nullable|integer|min:0|max:50',
            'reminder_escalate_after'     => 'sometimes|nullable|integer|min:0|max:50',
            'reminder_escalation_email'   => 'sometimes|nullable|email|max:150',
        ]);

        return response()->json(BillingSettings::save($data));
    }

    /** POST billing/run/{job}: close | dunning | reminders (owner). */
    public function run(string $job): JsonResponse
    {
        return response()->json(match ($job) {
            'close'   => (new CloseBillingPeriods)->execute(),
            'dunning' => (new RunDunning)->execute(),
            'reminders' => (new \Innertia\Billing\UseCases\SendPaymentReminders)->execute(),
            default   => abort(404),
        });
    }
}
