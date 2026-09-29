<?php

namespace App\Http\Controllers;

use App\Models\ActivityDocument;
use App\Services\AccessLogger;
use App\Services\DocumentStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Streams a decrypted attachment to a signed-in user, and logs it (CF-06, CF-08). */
class DocumentController extends Controller
{
    public function __invoke(Request $request, ActivityDocument $document, DocumentStore $store, AccessLogger $log): Response
    {
        abort_unless($request->user()->can('activities.view'), 403);

        $log->log(AccessLogger::DOWNLOAD, $document, ['activity_id' => $document->activity_id, 'name' => $document->original_name]);

        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $document->original_name);
        $inline = $request->boolean('inline') && in_array($document->mime_type, ['application/pdf', 'image/png', 'image/jpeg'], true);

        return response($store->contents($document), 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$safeName.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
