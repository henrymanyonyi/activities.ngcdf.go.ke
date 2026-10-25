<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Enums\ParticipantRole;
use App\Enums\ReviewOffice;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Show as ActivityShow;
use App\Livewire\Memos\Show as MemoShow;
use App\Models\Activity;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\ReportReceipt;
use App\Models\Staff;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use App\Support\WorkingDays;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->dept = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->dept);
    $this->acos = assistant();
    $this->ceo = ceo();

    // An approved two-day memo with one shared cost line.
    $this->memo = departmentMemo($this->hod, ['start_date' => today()->addDays(10)->toDateString(), 'days' => 2]);
    app(ActivityEditor::class)->savePlannedCost($this->memo, ['cost_category_id' => CostCategory::query()->where('code', 'other')->value('id'), 'description' => 'Venue', 'estimated_amount' => '100000'], $this->hod);
    // And two officers' own costs, so the report form names each officer (strict mode only flags lazy loads across several rows).
    app(ActivityEditor::class)->addStaff($this->memo, [Staff::factory()->create(['department_id' => $this->dept->id])->id], ParticipantRole::Member, $this->hod);
    foreach ($this->memo->participants()->pluck('id') as $participantId) {
        app(ActivityEditor::class)->savePlannedCost($this->memo, ['cost_category_id' => CostCategory::query()->where('code', 'travel')->value('id'), 'activity_participant_id' => $participantId, 'description' => 'Bus fare', 'estimated_amount' => '3000'], $this->hod);
    }
    $this->lifecycle->submitForReview($this->memo->fresh(), $this->hod, ReviewOffice::AssistantChiefOfStaff);
    $this->lifecycle->forward($this->memo->fresh(), $this->acos);
    $this->lifecycle->decide($this->memo->fresh(), DecisionType::Approved, null, $this->ceo);
});

/** @return array<int, string> every cost line of the memo at the given amount */
function spentOnEveryLine(Activity $memo, string $amount): array
{
    return $memo->costs()->pluck('id')->mapWithKeys(fn ($id) => [$id => $amount])->all();
}

it('starts an approved activity on its start date and completes it after its end date, with the report due 7 working days later', function () {
    $this->travelTo($this->memo->start_date);
    $this->artisan('activities:daily')->assertSuccessful();
    expect($this->memo->fresh()->status)->toBe(ActivityStatus::InProgress);

    $this->travelTo($this->memo->end_date->copy()->addDay());
    $this->artisan('activities:daily')->assertSuccessful();

    expect($this->memo->fresh())
        ->status->toBe(ActivityStatus::Completed)
        ->report_due_on->toDateString()->toBe(WorkingDays::add($this->memo->end_date, 7)->toDateString());
});

it('lets the Head of Department submit the report with what was spent, which counts as spent at once, and the Office of the CEO accept or return it', function () {
    $this->travelTo($this->memo->end_date->copy()->addDay());
    $this->artisan('activities:daily');
    $memo = $this->memo->fresh();
    $fy = FinancialYear::for($memo->start_date);
    $committedBefore = app(BudgetPosition::class)->forDepartment($fy, $this->dept->id)['committed'];

    expect(fn () => $this->lifecycle->submitReport($memo, ['outputs_achieved' => 'Done'], [], null, $this->hod))
        ->toThrow(ActivityWorkflowException::class, 'every cost line');

    Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $memo])
        ->assertSee('Submit activity report')->assertSee('Due in')
        ->call('openReport')
        ->set('outputsAchieved', 'Systems reviewed; six officers trained.')
        ->set('actuals', spentOnEveryLine($memo, '90,000'))
        ->call('submitReport')->assertHasNoErrors();

    // The register shows the activity's RAG, which reads the report, without lazy loading it.
    $this->actingAs($this->ceo)->get(route('activities.index'))->assertOk();

    $position = app(BudgetPosition::class)->forDepartment($fy, $this->dept->id);
    $planned = (int) round((float) $memo->fresh()->estimated_total * 100);
    expect($memo->fresh()->status)->toBe(ActivityStatus::Completed)
        ->and($memo->fresh()->report->status)->toBe(ReportReceipt::STATUS_SUBMITTED)
        ->and($position['spent'])->toBe($memo->costs()->count() * 90_000_00)
        ->and($position['committed'])->toBe($committedBefore - $planned)
        ->and($this->ceo->fresh()->notifications()->where('data->kind', 'report_submitted')->count())->toBe(1);

    // Returned for correction, then corrected: still timed from the first submission.
    $this->travel(3)->days();
    Livewire::actingAs($this->acos)->test(ActivityShow::class, ['activity' => $memo->fresh()])
        ->assertSee('Accept report')
        ->set('reason', 'Attach the attendance list.')->call('returnReport');
    expect($memo->fresh()->report->status)->toBe(ReportReceipt::STATUS_RETURNED);

    $this->lifecycle->submitReport($memo->fresh(), ['outputs_achieved' => 'Systems reviewed; attendance attached.'], spentOnEveryLine($memo, '95000'), null, $this->hod);
    $this->lifecycle->acceptReport($memo->fresh(), $this->acos);

    expect($memo->fresh())->status->toBe(ActivityStatus::ReportReceived)
        ->report->received_on->toDateString()->toBe($this->memo->end_date->copy()->addDay()->toDateString())
        ->and($memo->fresh()->reportTimelinessLabel())->toBe('Submitted on time');
});

it('tracks a report that comes in after the due period as late, and lists it for the CEO', function () {
    $this->travelTo($this->memo->end_date->copy()->addDay());
    $this->artisan('activities:daily');
    $memo = $this->memo->fresh();

    $this->travelTo(WorkingDays::add($memo->report_due_on, 2));
    expect($memo->fresh()->isReportOverdue())->toBeTrue()
        ->and($memo->fresh()->reportTimelinessLabel())->toBe('Overdue by 2 working days');

    $this->lifecycle->submitReport($memo, ['outputs_achieved' => 'Done late.'], spentOnEveryLine($memo, '0'), null, $this->hod);

    expect($memo->fresh()->isReportOverdue())->toBeFalse()
        ->and($memo->fresh()->reportTimelinessLabel())->toBe('Submitted 2 working days late');

    $this->actingAs($this->ceo)->get(route('reports.show', ['report-compliance', 'fy' => FinancialYear::for($memo->end_date)->label()]))
        ->assertOk()->assertSee('Activity Report Compliance')->assertSee($memo->reference)->assertSee('Submitted 2 working days late')->assertSee('Awaiting acceptance');
});

it('keeps the report to the department\'s own Head and to a completed activity', function () {
    $other = headOfDepartment(Department::query()->where('name', '!=', 'ICT')->firstOrFail());

    expect(fn () => $this->lifecycle->submitReport($this->memo->fresh(), ['outputs_achieved' => 'Early'], spentOnEveryLine($this->memo, '0'), null, $this->hod))
        ->toThrow(ActivityWorkflowException::class, 'once the activity is complete');

    $this->travelTo($this->memo->end_date->copy()->addDay());
    $this->artisan('activities:daily');

    expect(fn () => $this->lifecycle->submitReport($this->memo->fresh(), ['outputs_achieved' => 'Not mine'], spentOnEveryLine($this->memo, '0'), null, $other))
        ->toThrow(AuthorizationException::class);
});
