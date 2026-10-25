<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Services\AccessLogger;
use App\Services\DelegatedMemos;
use App\Services\MemoDocument;
use Symfony\Component\HttpFoundation\Response;

/**
 * The generated memo for the officer preparing it through a memo request, once they have confirmed
 * it is them (App\Services\DelegatedMemos). Only that memo, and only while the link is open. Logged (CF-06).
 */
class MemoRequestPreviewController extends Controller
{
    public function __invoke(string $token, DelegatedMemos $memos, MemoDocument $memo, AccessLogger $log): Response
    {
        $link = $memos->find($token);
        abort_unless($link && $link->isUsable() && $memos->isVerified($link) && $link->activity_id, 404);

        $activity = Activity::query()->findOrFail($link->activity_id);
        $log->log(AccessLogger::PRINT, $activity, ['document' => 'activity_memo', 'memo_request' => $link->id], email: $link->recipient_email);

        $response = $memo->pdf($activity)->stream($memo->filename($activity));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
