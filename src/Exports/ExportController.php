<?php

namespace Innertia\Exports;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\Models\TenantExportRecord;

class ExportController extends Controller
{
    /**
     * GET /exports
     *
     * List all exports for the current tenant (or app in non-saas mode).
     * Ordered by most recent first.
     */
    public function index(): JsonResponse
    {
        $tenantId = function_exists('tenant') ? tenant('id') : null;

        $exports = TenantExportRecord::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TenantExportRecord $r) => $this->format($r));

        return response()->json($exports);
    }

    /**
     * GET /exports/{id}
     *
     * Get status and detail of a specific export.
     * Returns a temporary download URL when status = completed.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = function_exists('tenant') ? tenant('id') : null;

        $export = TenantExportRecord::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return response()->json($this->format($export, withDownloadUrl: true));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function format(TenantExportRecord $record, bool $withDownloadUrl = false): array
    {
        $data = [
            'id'           => $record->id,
            'status'       => $record->status,      // pending | processing | completed | failed
            'size_mb'      => $record->sizeMb(),
            'checksum'     => $record->checksum,
            'error'        => $record->error,
            'completed_at' => $record->completed_at?->toISOString(),
            'created_at'   => $record->created_at->toISOString(),
        ];

        if ($withDownloadUrl && $record->status === 'completed') {
            $data['download_url'] = $record->downloadUrl(ttlMinutes: 60);
        }

        return $data;
    }
}
