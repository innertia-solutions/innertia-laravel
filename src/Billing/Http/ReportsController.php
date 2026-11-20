<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Billing\Support\BillingReports;
use Innertia\Billing\Support\XlsxExport;
use Symfony\Component\HttpFoundation\Response;

/** Platform: collection reports (JSON) and their .xlsx export. */
class ReportsController extends Controller
{
    /** GET billing/reports/{report}?from=&to=&group_by= */
    public function show(Request $request, string $report): JsonResponse
    {
        return response()->json(BillingReports::run($report, $this->params($request)));
    }

    public function export(Request $request, string $report): Response
    {
        $result = BillingReports::run($report, $this->params($request));
        $rows = $result['rows'];
        if ($result['totals'] !== []) {
            $first = array_key_first($result['columns']);
            $rows[] = [$first => 'Total'] + $result['totals'];
        }

        return XlsxExport::download($result['title'], $result['columns'], $rows,
            [[$result['title']], ['Desde', $result['from'], 'Hasta', $result['to']]]);
    }

    private function params(Request $request): array
    {
        return $request->validate([
            'from'     => 'nullable|date_format:Y-m-d',
            'to'       => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'group_by' => 'nullable|in:month,client,plan,product,method',
        ]);
    }
}
