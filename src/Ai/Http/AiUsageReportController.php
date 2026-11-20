<?php

namespace Innertia\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Ai\Usage\UsageReport;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Innertia;

/** Aggregated AI usage: platform (all spaces or one) and backoffice (active space only). */
class AiUsageReportController extends Controller
{
    /** GET ai/usage/report?from=YYYY-MM-DD&to=YYYY-MM-DD&tenant_id= | ?month=YYYY-MM */
    public function platform(Request $request): JsonResponse
    {
        $data = $this->validated($request) + $request->validate(['tenant_id' => 'nullable|string|max:64']);
        $report = $this->report($data, $data['tenant_id'] ?? null);
        $summary = $report->summary(withTenants: ! isset($data['tenant_id']));

        if (isset($summary['by_tenant'])) {
            $summary['by_tenant'] = $this->withTenantNames($summary['by_tenant']);
        }

        return response()->json($summary);
    }

    /** GET ai/usage/summary?month=YYYY-MM — the active space. */
    public function tenant(Request $request): JsonResponse
    {
        $tenantId = Innertia::tenancyEnabled() ? Innertia::tenant()?->getKey() : null;
        if (Innertia::tenancyEnabled() && $tenantId === null) {
            throw new ForbiddenException('No hay un espacio activo.');
        }

        $report = $this->report($this->validated($request), $tenantId !== null ? (string) $tenantId : null);

        return response()->json($report->summary());
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'from'  => 'nullable|date_format:Y-m-d|required_with:to',
            'to'    => 'nullable|date_format:Y-m-d|after_or_equal:from|required_with:from',
        ]);
    }

    private function report(array $data, ?string $tenantId): UsageReport
    {
        if (! empty($data['from'])) {
            return new UsageReport($data['from'], $data['to'], $tenantId);
        }

        return UsageReport::month($data['month'] ?? null, $tenantId);
    }

    private function withTenantNames(array $rows): array
    {
        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
        $ids = array_values(array_filter(array_column($rows, 'tenant_id')));

        try {
            $tenants = $ids === [] ? collect() : $model::query()->whereIn('id', $ids)->get(['id', 'key', 'name'])->keyBy(fn ($t) => (string) $t->id);
        } catch (\Throwable) {
            $tenants = collect();
        }

        return array_map(fn ($row) => $row + [
            'tenant_key'  => $tenants->get((string) $row['tenant_id'])?->key,
            'tenant_name' => $tenants->get((string) $row['tenant_id'])?->name,
        ], $rows);
    }
}
