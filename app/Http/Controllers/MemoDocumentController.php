<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Services\AccessLogger;
use App\Services\MemoDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The generated activity memo and annexes as a PDF: shown in the browser to
 * preview, or downloaded. The Office of the CEO sees any memo, a Head of
 * Department only their own department's. Every copy is logged (CF-06).
 */
class MemoDocumentController extends Controller
{
    public function __invoke(Request $request, Activity $activity, MemoDocument $memo, AccessLogger $log): Response
    {
        abort_unless($activity->isVisibleTo($request->user()), 403);

        $download = $request->boolean('download');
        $log->log($download ? AccessLogger::DOWNLOAD : AccessLogger::PRINT, $activity, ['document' => 'activity_memo']);

        $pdf = $memo->pdf($activity, $request->user());

        $response = $download ? $pdf->download($memo->filename($activity)) : $pdf->stream($memo->filename($activity));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
