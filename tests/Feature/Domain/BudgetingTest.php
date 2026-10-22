<?php

use App\Enums\BudgetStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Form;
use App\Livewire\Budgets\Index;
use App\Livewire\Budgets\Show;
use App\Models\BudgetAdjustment;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\User;
use App\Services\Budgeting;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->budgeting = app(Budgeting::class);
    $this->ceo = ceo();
    $this->cos = chiefOfStaff();
    $this->acos = assistant();
    $this->dept = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->fy = FinancialYear::current();
});

function allocationOf(object $test): ?int
{
    return app(BudgetPosition::class)->forDepartment($test->fy, $test->dept->id)['allocation'];
}

it('counts a budget the Chief of Staff prepares only once the CEO approves it, after a return for correction', function () {
    $budget = $this->budgeting->create($this->dept->id, $this->fy->label(), null, $this->cos);
    $this->budgeting->saveLine($budget, ['code' => '2210300', 'name' => 'Domestic travel', 'amount' => '1,000,000'], $this->cos);

    expect($budget->fresh()->status)->toBe(BudgetStatus::Draft)->and(allocationOf($this))->toBeNull();

    $this->budgeting->submit($budget, $this->cos);
    expect($this->ceo->fresh()->notifications()->count())->toBe(1)
        ->and(fn () => $this->budgeting->saveLine($budget->fresh(), ['name' => 'Fuel', 'amount' => '5000'], $this->cos))->toThrow(ActivityWorkflowException::class)
        ->and(fn () => $this->budgeting->approve($budget->fresh(), $this->cos))->toThrow(AuthorizationException::class)
        ->and(allocationOf($this))->toBeNull();

    $this->budgeting->returnForCorrection($budget->fresh(), 'Travel is KES 900,000 in the printed estimates.', $this->ceo);
    expect($budget->fresh())->status->toBe(BudgetStatus::Returned)->return_reason->toBe('Travel is KES 900,000 in the printed estimates.')
        ->and($this->cos->fresh()->notifications()->first()->data['url'])->toBe(route('budgets.show', $budget));

    $this->budgeting->saveLine($budget->fresh(), ['code' => '2210300', 'name' => 'Domestic travel', 'amount' => '900000'], $this->cos, $budget->lines()->sole());
    $this->budgeting->submit($budget->fresh(), $this->cos);
    $this->budgeting->approve($budget->fresh(), $this->ceo);

    expect($budget->fresh())->status->toBe(BudgetStatus::Approved)->approved_by->toBe($this->ceo->id)
        ->and(allocationOf($this))->toBe(900_000_00)
        ->and(fn () => $this->budgeting->saveLine($budget->fresh(), ['name' => 'Fuel', 'amount' => '5000'], $this->cos))->toThrow(ActivityWorkflowException::class, 'Propose an adjustment')
        ->and(fn () => $this->budgeting->approve($budget->fresh(), $this->ceo))->toThrow(ActivityWorkflowException::class);
});

it('lets the CEO approve a draft the CEO prepared, but not anyone else\'s draft until it is submitted', function () {
    $own = $this->budgeting->create($this->dept->id, $this->fy->label(), null, $this->ceo);
    $this->budgeting->saveLine($own, ['name' => 'Travel', 'amount' => '100000'], $this->ceo);
    $this->budgeting->approve($own->fresh(), $this->ceo);
    expect($own->fresh()->status)->toBe(BudgetStatus::Approved);

    $other = Department::query()->where('name', 'Programmes')->firstOrFail();
    $draft = $this->budgeting->create($other->id, $this->fy->label(), null, $this->acos);
    $this->budgeting->saveLine($draft, ['name' => 'Travel', 'amount' => '100000'], $this->acos);

    expect(fn () => $this->budgeting->approve($draft->fresh(), $this->ceo))->toThrow(ActivityWorkflowException::class, 'submitted to you first')
        ->and(fn () => $this->budgeting->create($this->dept->id, $this->fy->label(), null, $this->acos))->toThrow(ActivityWorkflowException::class, 'already has a budget');
});

it('keeps budgets to the CEO, the Chief of Staff and the Assistant', function () {
    $hod = headOfDepartment($this->dept);
    $admin = User::factory()->admin()->create();

    expect(fn () => $this->budgeting->create($this->dept->id, $this->fy->label(), null, $hod))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->budgeting->create($this->dept->id, $this->fy->label(), null, $admin))->toThrow(AuthorizationException::class);

    $this->actingAs($hod)->get(route('budgets.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('budgets.index'))->assertForbidden();
    $this->actingAs($admin)->get(route('settings.reference', ['tab' => 'budgets']))->assertOk()->assertDontSee('Add budget line');
    $this->actingAs($this->acos)->get(route('budgets.index'))->assertOk()->assertSee('Department budgets')->assertSee('Budget adjustments');
});

it('adjusts an approved budget during the year only once the CEO approves, never taking a line below zero', function () {
    $travel = approvedBudgetLine($this->dept, $this->fy->label(), '500000', '2210300', 'Travel');
    $budget = $travel->budget;
    $fuel = approvedBudgetLine($this->dept, $this->fy->label(), '100000', null, 'Fuel');
    expect(allocationOf($this))->toBe(600_000_00);

    // A supplementary allocation under a new line, proposed by the Assistant.
    $supplementary = $this->budgeting->saveAdjustment($budget, ['kind' => BudgetAdjustment::KIND_SUPPLEMENTARY, 'new_line_code' => '2211000', 'new_line_name' => 'Materials', 'amount' => '50000', 'reason' => 'Supplementary estimates I'], $this->acos);
    $this->budgeting->submit($supplementary, $this->acos);
    expect(allocationOf($this))->toBe(600_000_00);
    $this->budgeting->approve($supplementary->fresh(), $this->ceo);

    $materials = $budget->lines()->where('name', 'Materials')->sole();
    expect(allocationOf($this))->toBe(650_000_00)
        ->and($this->budgeting->lineAmounts($budget)[$materials->id])->toBe(['original' => 0, 'adjustments' => 50_000_00, 'current' => 50_000_00]);

    // A reallocation moves money without changing the total; a reduction cannot take more than the line has.
    $move = $this->budgeting->saveAdjustment($budget, ['kind' => BudgetAdjustment::KIND_REALLOCATION, 'from_budget_line_id' => $fuel->id, 'budget_line_id' => $travel->id, 'amount' => '40000', 'reason' => 'More county visits'], $this->ceo);
    $this->budgeting->approve($move, $this->ceo);

    expect(allocationOf($this))->toBe(650_000_00)
        ->and($this->budgeting->lineAmounts($budget)[$fuel->id]['current'])->toBe(60_000_00)
        ->and($this->budgeting->lineAmounts($budget)[$travel->id]['current'])->toBe(540_000_00)
        ->and(fn () => $this->budgeting->saveAdjustment($budget, ['kind' => BudgetAdjustment::KIND_REDUCTION, 'budget_line_id' => $fuel->id, 'amount' => '60000.01', 'reason' => 'Cut'], $this->cos))
        ->toThrow(ActivityWorkflowException::class, 'only KES 60,000.00');

    $cut = $this->budgeting->saveAdjustment($budget, ['kind' => BudgetAdjustment::KIND_REDUCTION, 'budget_line_id' => $fuel->id, 'amount' => '10000', 'reason' => 'Treasury cut'], $this->cos);
    $this->budgeting->submit($cut, $this->cos);
    $this->budgeting->returnForCorrection($cut->fresh(), 'Make it 20,000.', $this->ceo);
    $this->budgeting->saveAdjustment($budget, ['kind' => BudgetAdjustment::KIND_REDUCTION, 'budget_line_id' => $fuel->id, 'amount' => '20000', 'reason' => 'Treasury cut'], $this->cos, $cut->fresh());
    $this->budgeting->submit($cut->fresh(), $this->cos);
    $this->budgeting->approve($cut->fresh(), $this->ceo);

    expect(allocationOf($this))->toBe(630_000_00)
        ->and($this->budgeting->totals($budget->fresh()))->toBe(['original' => 600_000_00, 'adjustments' => 30_000_00, 'current' => 630_000_00, 'pending' => 0]);
});

it('lets a memo be charged only to a line of an approved budget', function () {
    $draft = $this->budgeting->create($this->dept->id, $this->fy->label(), null, $this->cos);
    $draftLine = $this->budgeting->saveLine($draft, ['name' => 'Draft travel', 'amount' => '1000'], $this->cos);
    $hod = headOfDepartment($this->dept);

    Livewire::actingAs($hod)->test(Form::class)
        ->set('start_date', today()->addDays(10)->toDateString())
        ->assertDontSee('Draft travel')
        ->set('budget_line_id', (string) $draftLine->id)
        ->call('save')->assertHasErrors('budget_line_id');
});

it('takes a budget from draft to approval on the screens', function () {
    Livewire::actingAs($this->cos)->test(Index::class)
        ->assertSee('ICT')->assertSee('No budget')
        ->call('openPrepare', $this->dept->id)->call('prepare')
        ->assertRedirect();

    $budget = $this->dept->hasMany(DepartmentBudget::class)->sole();

    Livewire::actingAs($this->cos)->test(Show::class, ['budget' => $budget])
        ->call('openLine')->set('lineName', 'Domestic travel')->set('lineAmount', '250,000')->call('saveLine')->assertHasNoErrors()
        ->assertSee('Submit to the CEO')
        ->call('ask', 'submit', 'budget')->call('confirm');

    Livewire::actingAs($this->ceo)->test(Show::class, ['budget' => $budget])
        ->assertSee('Return for correction')
        ->call('openReturn', 'budget')->call('sendBack')->assertHasErrors('returnReason')
        ->call('ask', 'approve', 'budget')->call('confirm')
        ->assertSee('Allocation now')->assertSee('Propose adjustment');

    expect($budget->fresh()->status)->toBe(BudgetStatus::Approved)->and(allocationOf($this))->toBe(250_000_00);
});
