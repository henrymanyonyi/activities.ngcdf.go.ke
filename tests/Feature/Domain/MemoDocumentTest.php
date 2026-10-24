<?php

use App\Enums\DecisionType;
use App\Enums\ParticipantRole;
use App\Enums\ReviewOffice;
use App\Livewire\Activities\Form;
use App\Livewire\Memos\Show as MemoShow;
use App\Models\AccessLog;
use App\Models\ActivityCategory;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Models\User;
use App\Services\AccessLogger;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\AppSettings;
use App\Services\MemoDocument;
use App\Support\FinancialYear;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    DsaRate::create(['job_grade' => '5', 'amount' => '8400.00', 'effective_from' => '2020-07-01']);
    DsaRate::create(['job_grade' => '3', 'amount' => '14000.00', 'effective_from' => '2020-07-01']);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->editor = app(ActivityEditor::class);
    $this->memoDocument = app(MemoDocument::class);

    // A four-day field visit in Kakamega: three officers on one grade, one on another, a shared vehicle and a ticket.
    $this->memo = departmentMemo($this->hod, ['days' => 4, 'risks' => 'Delays if the county is not ready.', 'memo_sections' => [['heading' => 'Programme', 'body' => 'Day 1 courtesy call; days 2 to 4 site visits.']]]);
    $county = County::query()->where('name', 'Kakamega')->firstOrFail();
    $this->editor->addLocation($this->memo, ['county_id' => $county->id], $this->hod);
    $senior = Staff::factory()->create(['job_grade' => '3', 'department_id' => $this->ict->id]);
    $others = Staff::factory()->count(2)->create(['job_grade' => '5', 'department_id' => $this->ict->id]);
    $this->editor->addStaff($this->memo, [$senior->id, ...$others->pluck('id')->all()], ParticipantRole::Member, $this->hod);
    $this->editor->savePlannedCost($this->memo, ['cost_category_id' => CostCategory::query()->where('code', 'fuel')->value('id'), 'description' => 'Board vehicle', 'estimated_amount' => '30000'], $this->hod);
    $this->editor->savePlannedCost($this->memo, ['cost_category_id' => CostCategory::query()->where('code', 'travel')->value('id'), 'activity_participant_id' => $this->memo->participants()->where('staff_id', $senior->id)->value('id'), 'description' => 'Air ticket', 'estimated_amount' => '35000'], $this->hod);
    $this->memo->refresh();
});

it('builds the memo from what was captured, with every annex total agreeing with the memo', function () {
    $data = $this->memoDocument->data($this->memo);
    $total = 8400_00 * 3 * 3 + 14000_00 * 3 + 30000_00 + 35000_00; // DSA for 3 nights, the vehicle, the ticket

    expect($data['total'])->toBe($total)
        ->and((int) round((float) $this->memo->estimated_total * 100))->toBe($total)
        ->and(array_sum(array_column($data['byCategory'], 'amount')))->toBe($total)
        ->and(array_sum(array_column($data['byDepartment'], 'amount')))->toBe($total)
        ->and(array_sum(array_column($data['detailed'], 'amount')))->toBe($total)
        ->and($data['request'])->toContain('KES '.Money::format($total))
        ->and(collect($data['sections'])->pluck('heading')->all())->toBe(['Introduction', 'Objectives', 'Key Risks to be Mitigated', 'Programme'])
        ->and(collect($data['sections'])->pluck('number')->all())->toBe(['1.0', '2.0', '3.0', '4.0'])
        ->and($data['isDraft'])->toBeTrue();

    // Three 5 officers on the same DSA are one row of three, like the paper budget.
    $dsa = collect($data['detailed'])->where('item', 'DSA')->where('scale', '5')->sole();
    expect($dsa['pax'])->toBe(3)->and($dsa['days'])->toBe('3')->and($dsa['rate'])->toBe(8400_00)->and($dsa['amount'])->toBe(8400_00 * 3 * 3)
        ->and(collect($data['byDepartment'])->firstWhere('label', 'ICT')['people'])->toBe(4)
        ->and(collect($data['byDepartment'])->firstWhere('label', 'Shared costs (whole activity)')['amount'])->toBe(30000_00)
        ->and(count($data['itinerary']))->toBe(4);
});

it('lists what is missing without inventing it, and blocks submission only on what the memo cannot go without', function () {
    $bare = $this->editor->create(['title' => 'Bare', 'purpose' => 'Test', 'activity_category_id' => ActivityCategory::query()->value('id'), 'start_date' => today()->addDays(9)->toDateString(), 'days' => 2], $this->hod);
    $issues = collect($this->memoDocument->issues($bare));

    expect($issues->where('blocking', true)->pluck('message')->all())->toBe([
        'Write the introduction and justification (section 1.0 of the memo).',
        'Add the venue or location.',
        'Add at least one officer to the team.',
    ])->and($issues->where('blocking', false)->pluck('message')->implode(' '))
        ->toContain('Key risks to be mitigated (section 3.0) is empty.')
        ->toContain('No budget line is chosen')
        ->toContain('The budget has no cost lines yet');

    // The full memo has nothing blocking; the department's own estimate differs from the costed budget, which is flagged.
    expect($this->memoDocument->blockingIssues($this->memo))->toBe([])
        ->and(collect($this->memoDocument->issues($this->memo))->pluck('message')->implode(' '))->toContain('Your own estimate (KES 250,000.00) differs from the costed budget');
});

it('fills the signature blocks from the audit trail as the memo moves on', function () {
    app(AppSettings::class)->set('acos_forwards_via_cos', 1);
    $lifecycle = app(ActivityLifecycle::class);
    $acos = assistant();
    $cos = chiefOfStaff();
    $ceo = ceo();

    $lifecycle->submitForReview($this->memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);
    $lifecycle->forward($this->memo->fresh(), $acos);
    $lifecycle->forward($this->memo->fresh(), $cos);
    $lifecycle->decide($this->memo->fresh(), DecisionType::Approved, null, $ceo);

    $signatures = collect($this->memoDocument->data($this->memo->fresh())['signatures'])->keyBy('label');

    expect($signatures['Prepared by'])->name->toBe($this->hod->name)->designation->toBe('Head of Department, ICT')
        ->and($signatures['Reviewed by']['name'])->toBe($acos->name)
        ->and($signatures['Validated by']['name'])->toBe($cos->name)
        ->and($signatures['Approved by']['name'])->toBe($ceo->name)
        ->and($signatures['Approved by']['date'])->not->toBeNull();
});

it('names both the preparer and the Head of Department on a memo prepared on their behalf', function () {
    $cos = chiefOfStaff();
    $memo = capturedActivity($cos, ['organising_department_id' => $this->ict->id, 'responsible_hod_id' => $this->hod->id, 'responsible_hod_name' => $this->hod->name]);

    $prepared = collect($this->memoDocument->data($memo)['signatures'])->firstWhere('label', 'Prepared by');

    expect($prepared['name'])->toBe($cos->name)
        ->and($prepared['designation'])->toBe('Chief of Staff, on behalf of '.$this->hod->name.', Head of Department, ICT');
});

it('produces the PDF with each annex on its own page, for the department and the Office of the CEO only, and logs every copy', function () {
    $response = $this->actingAs($this->hod)->get(route('memos.document', $this->memo))->assertOk()->assertHeader('content-type', 'application/pdf');
    $pdf = $response->getContent();

    // The memo, then Annexes A, B and C, each starting a new page, numbered "Page n of N".
    expect(preg_match_all('#/Type\s*/Page[^s]#', $pdf))->toBe(4);

    $html = view('memos.document', $this->memoDocument->data($this->memo) + ['user' => $this->hod])->render();
    expect(substr_count($html, 'class="annex"'))->toBe(3)
        ->and($html)->not->toContain('RESTRICTED: FOR THE OFFICE OF THE CEO ONLY')
        ->and($html)->toContain('page-break-before: always')
        ->toContain('Annex A: Budget Breakdown')->toContain('Annex B: Detailed Activity Budget')->toContain('Annex C: Participant Itinerary')
        ->toContain('Delays if the county is not ready.')
        ->toContain('4.0 Programme')
        ->toContain('5.0 Request for Approval');

    $this->actingAs($this->hod)->get(route('memos.document', ['activity' => $this->memo, 'download' => 1]))
        ->assertOk()->assertHeader('content-disposition', 'attachment; filename='.$this->memo->reference.'-activity-memo-RESTRICTED.pdf');
    $this->actingAs(chiefOfStaff())->get(route('memos.document', $this->memo))->assertOk();
    $this->actingAs(headOfDepartment(Department::query()->where('name', '!=', 'ICT')->firstOrFail()))->get(route('memos.document', $this->memo))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('memos.document', $this->memo))->assertForbidden();

    expect(AccessLog::query()->where('subject_id', $this->memo->id)->whereIn('event', [AccessLogger::PRINT, AccessLogger::DOWNLOAD])->count())->toBe(3);
});

it('lets the Head of Department add risks and extra sections, preview the memo, and submit without uploading a signed copy', function () {
    $memo = $this->editor->create(['title' => 'Systems review', 'purpose' => 'Review systems', 'justification' => 'Due this quarter.', 'activity_category_id' => ActivityCategory::query()->value('id'), 'start_date' => today()->addDays(20)->toDateString(), 'days' => 1], $this->hod);
    $this->editor->addLocation($memo, ['venue' => 'Headquarters'], $this->hod);
    $this->editor->addStaff($memo, [Staff::factory()->create()->id], ParticipantRole::Member, $this->hod);

    Livewire::actingAs($this->hod)->test(Form::class, ['activity' => $memo->fresh()])
        ->set('risks', 'Systems downtime.')
        ->call('addSection')
        ->call('save')->assertHasErrors(['memo_sections.0.heading', 'memo_sections.0.body'])
        ->set('memo_sections.0.heading', 'Previous review')->set('memo_sections.0.body', 'Done in March.')
        ->call('save')->assertHasNoErrors();

    expect($memo->fresh())->risks->toBe('Systems downtime.')->memo_sections->toBe([['heading' => 'Previous review', 'body' => 'Done in March.']]);

    Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $memo->fresh()])
        ->assertSee('Ready to submit')->assertSee('Preview memo')->assertSee(route('memos.document', $memo))
        ->call('openSubmit')->call('submit')->assertHasNoErrors();

    expect($memo->fresh()->documents()->count())->toBe(0)
        ->and($memo->fresh()->status->value)->toBe('under_review');
});

it('charges the memo to the budget line chosen', function () {
    $line = approvedBudgetLine($this->ict, FinancialYear::for($this->memo->start_date)->label(), '5000000', '2210300');
    $this->editor->update($this->memo, ['budget_line_id' => $line->id], $this->hod);

    expect($this->memoDocument->data($this->memo->fresh())['request'])->toContain('charged under 2210300 · Domestic travel');
});
