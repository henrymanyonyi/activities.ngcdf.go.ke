<?php

use App\Enums\BudgetStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Budgets\Index;
use App\Livewire\Budgets\Show;
use App\Models\BudgetAdjustment;
use App\Models\BudgetReview;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\Staff;
use App\Reports\Definitions\BudgetAdjustmentsRegister;
use App\Services\Budgeting;
use App\Services\BudgetPosition;
use App\Services\StaffImporter;
use App\Support\FinancialYear;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->budgeting = app(Budgeting::class);
    $this->ceo = ceo();
    $this->cos = chiefOfStaff();
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->fy = FinancialYear::current();
});

/** A filled-in workplan template as an upload: ICT's network visits and laptops. */
function ictWorkplanUpload(): UploadedFile
{
    $csv = implode("\n", [
        'Code,Activity or budget line,Objective,Deliverables,Quantity,Unit cost (KES),Amount (KES),Quarters,Responsible officer',
        '2211300,Network support visits,Keep offices connected,Visit reports,24,35000,,"Q1,Q2,Q3,Q4",Head of Network Services',
        '3111002,Replace laptops,Working computers,40 laptops issued,40,95000,,Q2,ICT Officer Hardware',
    ])."\n";

    return UploadedFile::fake()->createWithContent('ict-workplan.csv', $csv);
}

function submittedIctWorkplan(object $test): DepartmentBudget
{
    $budget = $test->budgeting->create($test->ict->id, $test->fy->label(), null, $test->hod, 'Reliable ICT services for every office.');
    expect($test->budgeting->importLines($budget, ictWorkplanUpload(), $test->hod))->toBe(['added' => 2, 'updated' => 0, 'errors' => []]);
    $test->budgeting->submit($budget->fresh(), $test->hod);

    return $budget->fresh();
}

it('lets a Head of Department prepare their workplan from the template and submit it to the CEO', function () {
    $budget = submittedIctWorkplan($this);

    $visits = $budget->lines()->where('name', 'Network support visits')->sole();
    expect($visits)->amount->toBe('840000.00')->proposed_amount->toBe('840000.00')->quarters->toBe('Q1,Q2,Q3,Q4')->responsible_officer->toBe('Head of Network Services')
        ->and($budget)->status->toBe(BudgetStatus::Submitted)->prepared_by->toBe($this->hod->id)->workplan_file_name->toBe('ict-workplan.csv')
        ->and($this->budgeting->totals($budget)['original'])->toBe(4_640_000_00)
        ->and($budget->reviews()->sole())->event->toBe(BudgetReview::SUBMITTED)->snapshot->total->toBe('4640000.00')
        ->and($this->ceo->notifications()->sole()->data['url'])->toBe(route('budgets.show', $budget))
        ->and(app(BudgetPosition::class)->forDepartment($this->fy, $this->ict->id)['allocation'])->toBeNull();

    // Locked while the CEO reviews it; the department follows it on its own page only.
    expect(fn () => $this->budgeting->saveLine($budget, ['name' => 'Cables', 'amount' => '1000'], $this->hod))->toThrow(ActivityWorkflowException::class);
    $this->actingAs($this->hod)->get(route('budgets.show', $budget))->assertOk()->assertSee('With the CEO for review');
    $this->actingAs(headOfDepartment(Department::query()->where('name', 'Supply Chain')->firstOrFail()))->get(route('budgets.show', $budget))->assertForbidden();
});

it('rejects a workplan file with faulty rows and saves none of it', function () {
    $budget = $this->budgeting->create($this->ict->id, $this->fy->label(), null, $this->hod);
    $file = UploadedFile::fake()->createWithContent('bad.csv', "Activity or budget line,Quantity,Unit cost (KES),Quarters\n,2,100,Q1\nLaptops,many,100,Q5\n");

    expect($this->budgeting->importLines($budget, $file, $this->hod)['errors'])->toBe([
        'Row 2: Activity or budget line is required.',
        'Row 3: Quantity is not a number. Quarters must be Q1, Q2, Q3 or Q4 (not Q5).',
    ])->and($budget->lines()->count())->toBe(0)->and($budget->fresh()->workplan_file_path)->toBeNull();
});

it('lets the CEO comment, change amounts and return the workplan, and the Head revise and resubmit it, keeping the history', function () {
    $budget = submittedIctWorkplan($this);
    $laptops = $budget->lines()->where('name', 'Replace laptops')->sole();

    $this->budgeting->comment($budget, 'Phase the laptops over two years.', $this->ceo, $laptops);
    $this->budgeting->reviseLine($laptops, '1,900,000', 'Half this year.', $this->ceo);
    expect(fn () => $this->budgeting->reviseLine($laptops, '1', null, $this->hod))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->budgeting->returnForCorrection($budget->fresh(), ' ', $this->ceo))->toThrow(ActivityWorkflowException::class);
    $this->budgeting->returnForCorrection($budget->fresh(), 'Justify the visits per office.', $this->ceo);

    expect($laptops->fresh())->amount->toBe('1900000.00')->proposed_amount->toBe('3800000.00')
        ->and($budget->fresh()->status)->toBe(BudgetStatus::Returned)
        ->and($this->hod->notifications()->latest()->first()->data['message'])->toContain('Returned for correction');

    $this->actingAs($this->hod)->get(route('budgets.show', $budget))->assertOk()
        ->assertSee('Justify the visits per office.')->assertSee('Phase the laptops over two years.')->assertSee('Resubmit to the CEO');

    $this->budgeting->saveLine($budget->fresh(), ['name' => 'Replace laptops', 'code' => '3111002', 'quantity' => '20', 'unit_cost' => '95,000'], $this->hod, $laptops->fresh());
    $this->budgeting->submit($budget->fresh(), $this->hod);

    expect($budget->reviews()->reorder('id')->pluck('event')->all())->toBe([BudgetReview::SUBMITTED, BudgetReview::COMMENT, BudgetReview::AMOUNT_CHANGED, BudgetReview::RETURNED, BudgetReview::RESUBMITTED])
        ->and($budget->reviews()->where('event', BudgetReview::SUBMITTED)->sole()->snapshot['total'])->toBe('4640000.00')
        ->and($budget->reviews()->where('event', BudgetReview::RESUBMITTED)->sole()->snapshot['total'])->toBe('2740000.00');
});

it('makes the approved workplan the department\'s budget for the year, fixed from then on', function () {
    $budget = submittedIctWorkplan($this);
    expect(fn () => $this->budgeting->approve($budget, $this->hod))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->budgeting->approve($budget, $this->cos))->toThrow(AuthorizationException::class);

    $this->budgeting->approve($budget, $this->ceo);

    expect($budget->fresh())->status->toBe(BudgetStatus::Approved)->approved_by->toBe($this->ceo->id)->approved_at->not->toBeNull()
        ->and($budget->reviews()->first())->event->toBe(BudgetReview::APPROVED)->snapshot->total->toBe('4640000.00')
        ->and(app(BudgetPosition::class)->forDepartment($this->fy, $this->ict->id)['allocation'])->toBe(4_640_000_00)
        ->and(fn () => $this->budgeting->saveLine($budget->fresh(), ['name' => 'Cables', 'amount' => '1000'], $this->hod))->toThrow(ActivityWorkflowException::class, 'Propose an adjustment')
        ->and(fn () => $this->budgeting->reviseLine($budget->lines()->first(), '1', null, $this->ceo))->toThrow(ActivityWorkflowException::class)
        ->and(fn () => $budget->reviews()->first()->update(['comment' => 'x']))->toThrow(LogicException::class);
});

it('lets the CEO increase and reduce an approved allocation directly, keeping the approved figures and every change', function () {
    $budget = submittedIctWorkplan($this);
    $this->budgeting->approve($budget, $this->ceo);
    $visits = $budget->lines()->where('name', 'Network support visits')->sole();

    expect(fn () => $this->budgeting->adjustNow($budget->fresh(), ['kind' => BudgetAdjustment::KIND_SUPPLEMENTARY, 'budget_line_id' => $visits->id, 'amount' => '100000', 'reason' => 'More offices'], $this->cos))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->budgeting->adjustNow($budget->fresh(), ['kind' => BudgetAdjustment::KIND_SUPPLEMENTARY, 'budget_line_id' => $visits->id, 'amount' => '100000', 'reason' => ' '], $this->ceo))->toThrow(ActivityWorkflowException::class, 'reason');

    $up = $this->budgeting->adjustNow($budget->fresh(), ['kind' => BudgetAdjustment::KIND_SUPPLEMENTARY, 'budget_line_id' => $visits->id, 'amount' => '160,000', 'reason' => 'Supplementary estimates I', 'effective_on' => $this->fy->start()->addMonths(3)->toDateString()], $this->ceo);
    $down = $this->budgeting->adjustNow($budget->fresh(), ['kind' => BudgetAdjustment::KIND_REDUCTION, 'budget_line_id' => $visits->id, 'amount' => '300000', 'reason' => 'Treasury cut'], $this->ceo);

    expect($up)->status->toBe(BudgetStatus::Approved)->line_before->toBe('840000.00')->line_after->toBe('1000000.00')->effective_on->toDateString()->toBe($this->fy->start()->addMonths(3)->toDateString())
        ->and($down)->line_before->toBe('1000000.00')->line_after->toBe('700000.00')->approved_by->toBe($this->ceo->id)
        ->and($visits->fresh()->amount)->toBe('840000.00')
        ->and($this->budgeting->lineAmounts($budget->fresh())[$visits->id])->toMatchArray(['original' => 840_000_00, 'increases' => 160_000_00, 'decreases' => 300_000_00, 'current' => 700_000_00])
        ->and($this->budgeting->totals($budget->fresh()))->toMatchArray(['original' => 4_640_000_00, 'current' => 4_500_000_00])
        ->and(app(BudgetPosition::class)->forDepartment($this->fy, $this->ict->id)['allocation'])->toBe(4_500_000_00)
        ->and($this->hod->notifications()->where('data->message', 'like', 'Budget adjusted by the CEO%')->count())->toBe(2)
        ->and(fn () => $this->budgeting->adjustNow($budget->fresh(), ['kind' => BudgetAdjustment::KIND_REDUCTION, 'budget_line_id' => $visits->id, 'amount' => '700000.01', 'reason' => 'Too much'], $this->ceo))->toThrow(ActivityWorkflowException::class, 'only KES 700,000.00');

    // The register shows each change with the allocation before and after, and the department's approved and current budget.
    $rows = app(BudgetAdjustmentsRegister::class)->rows(['fy' => $this->fy->label()]);
    expect($rows->map(fn ($r) => [$r['kind'], $r['before'], $r['change'], $r['after']])->all())->toBe([
        ['Supplementary allocation (increase)', 840_000_00, 160_000_00, 1_000_000_00],
        ['Reduction', 1_000_000_00, -300_000_00, 700_000_00],
        ['Summary', 4_640_000_00, null, 4_500_000_00],
    ]);
});

it('asks the CEO to check a direct adjustment before applying it, on the screen', function () {
    $budget = submittedIctWorkplan($this);
    $this->budgeting->approve($budget, $this->ceo);
    $visits = $budget->lines()->where('name', 'Network support visits')->sole();

    Livewire::actingAs($this->ceo)->test(Show::class, ['budget' => $budget])
        ->call('openAdjustNow')
        ->set('adjKind', BudgetAdjustment::KIND_REDUCTION)->set('adjLine', (string) $visits->id)->set('adjAmount', '40,000')
        ->call('checkAdjustment')->assertHasErrors('adjReason')
        ->set('adjReason', 'Fewer visits')
        ->call('checkAdjustment')->assertSee('Apply this adjustment?')->assertSee('840,000.00 → 800,000.00')
        ->call('applyAdjustment')->assertHasNoErrors();

    expect($budget->adjustments()->sole())->status->toBe(BudgetStatus::Approved)->line_after->toBe('800000.00');
});

it('accepts only a document or spreadsheet as the workplan, and shows it only to the department and the Office of the CEO', function () {
    $budget = $this->budgeting->create($this->ict->id, $this->fy->label(), null, $this->hod);

    Livewire::actingAs($this->hod)->test(Show::class, ['budget' => $budget])
        ->set('workplanFile', UploadedFile::fake()->create('run.exe', 10, 'application/octet-stream'))->assertHasErrors('workplanFile')
        ->set('workplanFile', UploadedFile::fake()->create('ict-workplan.pdf', 20, 'application/pdf'))->assertHasNoErrors();

    expect($budget->fresh()->workplan_file_name)->toBe('ict-workplan.pdf');
    $this->actingAs($this->hod)->get(route('budgets.workplan', $budget))->assertOk()->assertDownload('ict-workplan.pdf');
    $this->actingAs($this->ceo)->get(route('budgets.workplan', $budget))->assertOk();
    $this->actingAs(headOfDepartment(Department::query()->where('name', 'Supply Chain')->firstOrFail()))->get(route('budgets.workplan', $budget))->assertNotFound();
    $this->actingAs($this->hod)->get(route('budgets.template'))->assertOk()->assertDownload('fapm-workplan-and-budget-template.xlsx');
});

it('imports the ICT example workplan in docs/guides as it is', function () {
    $budget = $this->budgeting->create($this->ict->id, $this->fy->label(), null, $this->hod);
    $file = new UploadedFile(base_path('docs/guides/FAPM-Workplan-ICT-Example-FY2026-27.xlsx'), 'FAPM-Workplan-ICT-Example-FY2026-27.xlsx', null, null, true);

    expect($this->budgeting->importLines($budget, $file, $this->hod))->toBe(['added' => 10, 'updated' => 0, 'errors' => []])
        ->and($this->budgeting->totals($budget->fresh())['original'])->toBe(27_462_000_00)
        ->and($budget->lines()->where('name', 'ICT policy and standards review')->sole())->amount->toBe('850000.00')->quantity->toBeNull();
});

it('imports the practice guide\'s ICT workplan and staff list as they are', function () {
    $budget = $this->budgeting->create($this->ict->id, $this->fy->label(), null, $this->hod);
    $file = new UploadedFile(base_path('docs/guides/FAPM-Practice-Workplan-ICT-FY2026-27.xlsx'), 'FAPM-Practice-Workplan-ICT-FY2026-27.xlsx', null, null, true);

    expect($this->budgeting->importLines($budget, $file, $this->hod))->toBe(['added' => 4, 'updated' => 0, 'errors' => []])
        ->and($this->budgeting->totals($budget->fresh())['original'])->toBe(6_570_000_00)
        ->and(app(StaffImporter::class)->import(base_path('docs/guides/FAPM-Practice-Staff-List.xlsx')))->toBe(['created' => 9, 'updated' => 0, 'skipped' => 0, 'errors' => []])
        ->and(Staff::query()->where('name', 'Faith Wanjiku')->value('job_grade'))->toBe('10')
        ->and(Staff::query()->where('name', 'Brian Kiptoo')->value('job_grade'))->toBe('INTERN');
});

it('creates the draft and imports a workplan already in Excel in one step, from the Head\'s budget page', function () {
    Livewire::actingAs($this->hod)->test(Index::class)
        ->assertSee('Prepare or import the workplan')->assertDontSee('All departments')
        ->call('openPrepare')
        ->set('prepareFile', ictWorkplanUpload())
        ->call('prepare')->assertHasNoErrors()->assertRedirect();

    $budget = DepartmentBudget::query()->sole();
    expect($budget)->department_id->toBe($this->ict->id)->status->toBe(BudgetStatus::Draft)
        ->and($budget->lines()->count())->toBe(2)
        ->and($budget->workplan_file_name)->toBe('ict-workplan.csv');
});

it('shows the CEO a Review and approve button on a workplan awaiting approval', function () {
    $budget = submittedIctWorkplan($this);

    $this->actingAs($this->ceo)->get(route('budgets.index', ['status' => 'submitted']))->assertOk()
        ->assertSee('Review and approve')->assertSee(route('budgets.show', $budget));
    $this->actingAs($this->cos)->get(route('budgets.index', ['status' => 'submitted']))->assertOk()->assertDontSee('Review and approve');
});
