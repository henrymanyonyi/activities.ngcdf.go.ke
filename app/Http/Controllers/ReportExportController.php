<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Reports\ReportCatalog;
use App\Services\AccessLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * FRD SR-03 / CF-06 / CF-07: exports carry only the filtered records, are
 * available only to users with reports.export, are marked RESTRICTED with the
 * user and time, and are written to the access log.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, AccessLogger $log): Response
    {
        $definition = ReportCatalog::find($report);
        abort_unless($definition && in_array($format, ['xlsx', 'pdf', 'print'], true), 404);
        abort_unless($request->user()->can($definition->permission()) && $request->user()->can('reports.export'), 403);

        $filters = $request->query();
        $rows = $definition->rows($filters);

        $log->log($format === 'print' ? AccessLogger::PRINT : AccessLogger::EXPORT, meta: [
            'report' => $definition->key(),
            'format' => $format,
            'rows' => $rows->count(),
            'filters' => collect($filters)->filter()->all(),
        ]);

        $name = Str::slug($definition->title()).'-'.now()->format('Ymd-Hi').'-RESTRICTED';
        $data = ['report' => $definition, 'filters' => $filters, 'rows' => $rows, 'user' => $request->user()];

        return match ($format) {
            'xlsx' => Excel::download(new ReportExport($definition, $filters, $rows, $request->user()), "{$name}.xlsx"),
            'pdf' => Pdf::loadView('reports.document', $data)->setPaper('a4', 'landscape')->download("{$name}.pdf"),
            'print' => response()->view('reports.document', $data + ['print' => true]),
        };
    }
}
