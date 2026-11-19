<?php

namespace Innertia\Mail\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innertia\Facades\Innertia;
use Innertia\Platform\Models\EmailLog;

/**
 * Log of emails sent by the platform (table email_logs) for /platform/emails.
 *
 *   GET emails        ?status=&type=&tenant_id=&search=&from=&to=&page=&per_page=
 *   GET emails/types  → distinct types (filter options)
 */
class EmailsController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->integer('per_page', 30), 1), 100);
        $hasEngine = Schema::hasColumn('email_logs', 'type');
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $page = EmailLog::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->string('status')))
            ->when($hasEngine && $request->filled('type'), fn ($q) => $q->where('type', (string) $request->string('type')))
            ->when($hasEngine && $request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', (string) $request->string('tenant_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')?->endOfDay()))
            ->when($request->filled('search'), function ($q) use ($request, $like) {
                $term = '%' . addcslashes((string) $request->string('search'), '%_\\') . '%';
                $q->where(fn ($w) => $w->where('to', $like, $term)->orWhere('subject', $like, $term));
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $tenantNames = $this->tenantNames($page->getCollection()->pluck('tenant_id')->filter()->unique()->values()->all());
        $page->getCollection()->transform(function (EmailLog $log) use ($tenantNames) {
            $row = $log->toArray();
            $row['tenant_name'] = $tenantNames[$log->tenant_id ?? ''] ?? null;

            return $row;
        });

        return $page;
    }

    public function types(): array
    {
        if (! Schema::hasColumn('email_logs', 'type')) {
            return ['data' => []];
        }

        return ['data' => EmailLog::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type')->all()];
    }

    /** @return array<string, string> */
    private function tenantNames(array $ids): array
    {
        if ($ids === [] || ! Innertia::tenancyEnabled()) {
            return [];
        }

        try {
            $model = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);

            return $model::query()->whereIn((new $model)->getKeyName(), $ids)->get()
                ->mapWithKeys(fn ($t) => [(string) $t->getKey() => (string) ($t->name ?? $t->key ?? $t->getKey())])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
