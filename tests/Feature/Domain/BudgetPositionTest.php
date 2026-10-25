<?php

use App\Enums\DecisionType;
use App\Livewire\Activities\Form;
use App\Livewire\Activities\Show;
use App\Livewire\DepartmentCosts;
use App\Livewire\Memos\Board;
use App\Models\Activity;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\Analytics;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use App\Support\Money;
use App\Support\Period;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->cos = chiefOfStaff();
    $this->ceo = ceo();
    $this->editor = app(ActivityEditor::class);
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->dept = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->start = today()->addDays(30);
    $this->fy = FinancialYear::for($this->start);
    approvedBudgetLine($this->dept, $this->fy->label(), '1000000', '2210300');

    $this->other = CostCategory::query()->where('code', 'other')->firstOrFail();
});

/** An activity for ICT with one shared planned cost (no DSA: one day), at the given status. */
function costedActivity(object $test, string $amount, ?string $decision = 'approved', int $offset = 0): Activity
{
    $activity = capturedActivity($test->cos, ['organising_department_id' => $test->dept->id, 'start_date' => $test->start->copy()->addDays($offset * 3)->toDateString(), 'days' => 1]);
    $test->editor->savePlannedCost($activity, ['cost_category_id' => $test->other->id, 'description' => 'Venue', 'estimated_amount' => $amount], $test->cos);

    if ($decision !== null) {
        $test->lifecycle->forward($activity->fresh(), $test->cos);
    }
    if ($decision === 'approved') {
        $test->lifecycle->decide($activity->fresh(), DecisionType::Approved, null, $test->ceo);
    }

    return $activity->fresh();
}

it('counts each cost line once, as spent or committed, keeps pending apart and releases what a cancellation did not spend', function () {
    $approved = costedActivity($this, '300000', 'approved', 0);
    $partlySpent = costedActivity($this, '100000', 'approved', 1);
    $this->lifecycle->confirmCommencement($partlySpent->forceFill(['start_date' => today()->subDay(), 'end_date' => today()->subDay()]), $this->cos);
    $this->editor->recordActual($partlySpent->costs()->sole(), '80000', 'PV-1', $this->cos);
    $cancelled = costedActivity($this, '50000', 'approved', 2);
    $this->lifecycle->cancel($cancelled, 'Called off', $this->cos);
    costedActivity($this, '40000', 'pending', 3); // awaiting the CEO
    costedActivity($this, '999999', null, 4); // a draft: not counted

    $row = app(BudgetPosition::class)->forYear($this->fy, $this->dept->id)->sole();

    expect($row)
        ->allocation->toBe(1_000_000_00)
        ->spent->toBe(80_000_00)
        ->committed->toBe(300_000_00)
        ->approved->toBe(400_000_00)
        ->approved_count->toBe(2)
        ->pending->toBe(40_000_00)
        ->pending_count->toBe(1)
        ->released->toBe(50_000_00)
        ->balance->toBe(1_000_000_00 - 80_000_00 - 300_000_00)
        ->utilisation->toBe(38)
        ->spent_percent->toBe(8);
})->skip(fn () => FinancialYear::for(today()->subDay())->label() !== FinancialYear::for(today()->addDays(30))->label(), 'The activities must fall in one financial year.');

it('shows the change since approval as an adjustment, without touching the approved amount', function () {
    $activity = costedActivity($this, '300000');
    $line = $activity->costs()->sole();

    $this->editor->savePlannedCost($activity, ['cost_category_id' => $this->other->id, 'description' => 'Venue', 'estimated_amount' => '280000'], $this->cos, $line, 'Cheaper venue');

    $row = app(BudgetPosition::class)->forYear($this->fy, $this->dept->id)->sole();
    expect($row)->approved->toBe(300_000_00)->committed->toBe(280_000_00)->adjustments->toBe(-20_000_00);
});

it('shows the CEO the balance if a memo is approved, and warns when it would go over the allocation', function () {
    costedActivity($this, '900000');
    $request = costedActivity($this, '150000', 'pending', 1);

    $position = app(BudgetPosition::class)->forActivity($request);
    expect($position)->balance->toBe(100_000_00)->this_request->toBe(150_000_00)->other_pending->toBe(0)->balance_after->toBe(-50_000_00);

    Livewire::actingAs($this->ceo)->test(Show::class, ['activity' => $request])
        ->call('openDecide', 'approved')
        ->assertSee('Balance if approved')
        ->assertSee('Approving this request would take the department KES 50,000.00 over its allocation for the year.')
        ->assertSee('Open the memo and annexes');
});

it('opens the Budget position tab and its export to the Office of the CEO only, with one department\'s detail', function () {
    $activity = costedActivity($this, '300000');
    costedActivity($this, '40000', 'pending', 1);

    Livewire::actingAs($this->ceo)->test(DepartmentCosts::class)
        ->set('lens', 'budget')->set('fy', $this->fy->label())
        ->assertSee('Budget position by department')
        ->assertSee(Money::format(700_000_00))
        ->set('department', (string) $this->dept->id)
        ->assertSee('Approved activities · ICT')
        ->assertSee($activity->title)
        ->assertSee('Pending requests');

    $this->actingAs($this->ceo)->get(route('reports.export', ['budget-position', 'xlsx', 'fy' => $this->fy->label()]))->assertOk();
    $this->actingAs($this->ceo)->get(route('reports.show', 'budget-position'))->assertOk()->assertSee('Departmental Budget Position');
    $this->actingAs(headOfDepartment($this->dept))->get(route('department-costs', ['lens' => 'budget']))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('reports.show', 'budget-position'))->assertNotFound();
});

it('makes the department costs balance the same figure as the budget position', function () {
    costedActivity($this, '300000');
    $cancelled = costedActivity($this, '50000', 'approved', 1);
    $this->lifecycle->cancel($cancelled, 'Called off', $this->cos);

    $requesting = app(Analytics::class)->departmentCosts(Period::fromFilters(['fy' => $this->fy->label()]))
        ->first(fn ($r) => $r['department']?->id === $this->dept->id);

    expect($requesting['variance'])->toBe(app(BudgetPosition::class)->forYear($this->fy, $this->dept->id)->sole()['balance'])
        ->and($requesting['variance'])->toBe(700_000_00);
});

it('shows a Head of Department their own department\'s budget, spending and balance before they prepare a memo, and nothing of another department', function () {
    costedActivity($this, '300000');
    $other = Department::query()->where('name', 'Programmes')->firstOrFail();
    approvedBudgetLine($other, $this->fy->label(), '7777777', null, 'Programmes travel');
    $hod = headOfDepartment($this->dept);

    $board = Livewire::actingAs($hod)->test(Board::class);
    if (FinancialYear::current()->label() === $this->fy->label()) {
        $board->assertSee('Budget FY '.$this->fy->label())->assertSee(Money::format(700_000_00))->assertSee('Available balance');
    }
    $board->assertDontSee('7,777,777.00');

    Livewire::actingAs($hod)->test(Form::class)
        ->set('start_date', $this->start->toDateString())
        ->assertSee('ICT budget · FY '.$this->fy->label())
        ->assertSee('KES '.Money::format(700_000_00))
        ->assertDontSee('7,777,777.00');

    $memo = departmentMemo($hod, ['start_date' => $this->start->copy()->addDays(5)->toDateString()]);
    Livewire::actingAs($hod)->test(App\Livewire\Memos\Show::class, ['activity' => $memo])
        ->assertSee('Budget position · ICT · FY '.$this->fy->label())
        ->assertSee('Balance if approved')
        ->assertDontSee('7,777,777.00');
});
