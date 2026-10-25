<?php

use App\Exports\ParticipantListTemplate;
use App\Exports\ReportExport;
use App\Models\Department;
use App\Services\MemoDocument;
use App\Support\DocumentFont;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Style\Style;

/**
 * Gill Sans MT is licensed with Microsoft Office and never committed, so DejaVu
 * Serif stands in for it here. dompdf remembers the file of every font family
 * for the rest of the PHP process, so these tests share one stand-in folder and
 * check that no text fell back to DejaVu Sans, whichever Gill Sans MT file
 * dompdf registered first.
 */
const STAND_IN_FONTS = __DIR__.'/../../../storage/framework/testing/document-fonts';

beforeEach(function () {
    Storage::fake('local');
    File::ensureDirectoryExists(STAND_IN_FONTS.'/fonts');
    File::ensureDirectoryExists(STAND_IN_FONTS.'/cache');
    copy(base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSerif.ttf'), STAND_IN_FONTS.'/fonts/GIL_____.TTF');
    copy(base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSerif-Bold.ttf'), STAND_IN_FONTS.'/fonts/Gill Sans MT Bold.ttf');
    config([
        'activities.document_fonts' => STAND_IN_FONTS.'/fonts',
        'dompdf.options.font_dir' => STAND_IN_FONTS.'/cache',
        'dompdf.options.font_cache' => STAND_IN_FONTS.'/cache',
    ]);
});

afterAll(function () {
    File::deleteDirectory(STAND_IN_FONTS);
});

it('registers Gill Sans MT from Windows or Office for Mac file names, and nothing when the files are missing', function () {
    $faces = DocumentFont::faces();

    config(['activities.document_fonts' => STAND_IN_FONTS.'/none']);

    expect($faces)->toContain("font-family: 'Gill Sans MT'; font-weight: normal; font-style: normal; src: url('".STAND_IN_FONTS.'/fonts/GIL_____.TTF')
        ->toContain("font-weight: bold; font-style: normal; src: url('".STAND_IN_FONTS.'/fonts/Gill Sans MT Bold.ttf')
        ->not->toContain('font-style: italic')
        ->and(DocumentFont::faces())->toBe('')
        ->and(DocumentFont::installed())->toBeFalse();
});

it('sets every word of the memo, its annexes and page numbers in Gill Sans MT', function () {
    $memo = departmentMemo(headOfDepartment(Department::query()->where('name', 'ICT')->firstOrFail()));

    $pdf = app(MemoDocument::class)->pdf($memo, chiefOfStaff())->output();

    expect($pdf)->not->toContain('DejaVuSans');
});

it('sets report PDFs in Gill Sans MT, and leaves the print view to the font installed on the computer', function () {
    $this->actingAs(chiefOfStaff());

    $pdf = $this->get(route('reports.export', ['activity-register', 'pdf']))->getContent();
    $print = $this->get(route('reports.export', ['activity-register', 'print']));

    expect($pdf)->not->toContain('DejaVuSans');
    $print->assertSee("font-family: 'Gill Sans MT'", false)->assertDontSee('@font-face', false);
});

it('sets Gill Sans MT as the font of Excel exports', function () {
    // Faked: writing a real workbook needs a 16 MB buffer the suite cannot spare.
    Excel::fake();
    $this->travelTo('2026-10-24 10:00');

    $this->actingAs(chiefOfStaff())->get(route('reports.export', ['activity-register', 'xlsx']));

    Excel::assertDownloaded('activity-register-20261024-1000-RESTRICTED.xlsx', function (ReportExport $export) {
        return $export->defaultStyles(new Style)['font']['name'] === 'Gill Sans MT';
    });
    expect((new ParticipantListTemplate)->defaultStyles(new Style)['font']['name'])->toBe('Gill Sans MT');
});
