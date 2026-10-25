<?php

namespace App\Http\Controllers;

use App\Exports\WorkplanTemplate;
use App\Models\DepartmentBudget;
use App\Services\AccessLogger;
use App\Services\Budgeting;
use App\Services\DocumentStore;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** The workplan template, and a department's uploaded workplan document (decrypted, logged: CF-06, CF-08). */
class WorkplanFileController extends Controller
{
    public function template(): BinaryFileResponse
    {
        return Excel::download(new WorkplanTemplate, 'fapm-workplan-and-budget-template.xlsx');
    }

    public function show(Request $request, DepartmentBudget $budget, DocumentStore $store, AccessLogger $log): Response
    {
        // The Office of the CEO, or the department's own Head.
        abort_unless(Budgeting::canView($request->user(), $budget) && $budget->hasWorkplanFile(), 404);

        $log->log(AccessLogger::DOWNLOAD, $budget, ['name' => $budget->workplan_file_name]);

        return response($store->budgetFileContents($budget), 200, [
            'Content-Type' => $budget->workplan_file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $budget->workplan_file_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
