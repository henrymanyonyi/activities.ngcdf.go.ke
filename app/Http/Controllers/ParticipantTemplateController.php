<?php

namespace App\Http\Controllers;

use App\Exports\ParticipantListTemplate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ParticipantTemplateController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        return Excel::download(new ParticipantListTemplate, 'fapm-participant-list-template.xlsx');
    }
}
