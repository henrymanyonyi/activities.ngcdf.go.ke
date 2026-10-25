<?php

use App\Enums\DecisionType;
use App\Enums\InstructionKind;
use App\Enums\InstructionStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\DecisionQueue;
use App\Livewire\Instructions\Show as InstructionSheet;
use App\Models\ActionInstruction;
use App\Models\Activity;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use App\Services\ActionInstructions;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\AppSettings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(SettingsSeeder::class);
    $this->ceo = ceo();
    $department = fn (string $name) => Department::query()->where('name', $name)->firstOrFail();
    $this->financeHead = headOfDepartment($department('Financial Services - Accounts'));
    $this->procurementHead = headOfDepartment($department('Supply Chain'));
    $this->otherHead = headOfDepartment($department('Internal Audit'));
});

/** An activity awaiting the CEO, with a DSA line and an air ticket. */
function activityAwaitingTheCeo(): Activity
{
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos, ['days' => 3, 'justification' => 'Confidential reasons for the visit.'], [Staff::factory()->create(['name' => 'Wanjiru Example', 'job_grade' => '5'])]);
    app(ActivityEditor::class)->savePlannedCost($activity, [
        'cost_category_id' => CostCategory::query()->where('code', 'travel')->value('id'),
        'activity_participant_id' => $activity->participants()->value('id'),
        'description' => 'Air ticket Nairobi to Kisumu',
        'estimated_amount' => '18000',
        'travel_mode' => 'air',
    ], $cos);
    app(ActivityLifecycle::class)->submitForDecision($activity->fresh(), $cos);

    return $activity->fresh();
}

function instructableActivity(User $ceo): Activity
{
    $activity = activityAwaitingTheCeo();
    app(ActivityLifecycle::class)->decide($activity, DecisionType::Approved, null, $ceo);

    return $activity->fresh();
}

it('instructs Finance and Procurement when the CEO approves, alerting only their Heads', function () {
    $activity = activityAwaitingTheCeo();

    Livewire::actingAs($this->ceo)->test(DecisionQueue::class)
        ->call('open', $activity->id, 'approved')
        ->assertSee('Finance: process the imprest')
        ->set('instruct', ['imprest', 'tickets'])
        ->set('instructionNote', 'Economy class.')
        ->call('decide')
        ->assertHasNoErrors();

    expect($activity->instructions()->with('department')->orderBy('kind')->get()->map(fn ($i) => [$i->kind, $i->department->name, $i->status, $i->note, $i->sent_by])->all())->toBe([
        [InstructionKind::Imprest, 'Financial Services - Accounts', InstructionStatus::Sent, 'Economy class.', $this->ceo->id],
        [InstructionKind::Tickets, 'Supply Chain', InstructionStatus::Sent, 'Economy class.', $this->ceo->id],
    ]);
    expect($this->financeHead->notifications()->pluck('data')->pluck('kind')->all())->toBe(['instruction_sent'])
        ->and($this->procurementHead->notifications()->first()->data['url'])->toBe(route('instructions.show', $activity->instructions()->where('kind', 'tickets')->value('id')))
        ->and($this->otherHead->notifications()->count())->toBe(0);
});

it('lets only the CEO instruct, only on an approved activity, and only once of each kind', function () {
    $waiting = activityAwaitingTheCeo();
    $service = app(ActionInstructions::class);

    expect(fn () => $service->send($waiting, [InstructionKind::Imprest], null, $this->ceo))
        ->toThrow(ActivityWorkflowException::class, 'only on an approved activity');

    $approved = instructableActivity($this->ceo);
    expect(fn () => $service->send($approved, [InstructionKind::Tickets], null, chiefOfStaff()))->toThrow(AuthorizationException::class);

    $service->send($approved, [InstructionKind::Tickets], null, $this->ceo);
    expect(fn () => $service->send($approved, [InstructionKind::Tickets], null, $this->ceo))
        ->toThrow(ActivityWorkflowException::class, 'Procurement has already been instructed')
        ->and($approved->instructions()->count())->toBe(1);
});

it('shows Procurement an instruction sheet with the team and travel costs, never the memo or other cost lines', function () {
    $activity = instructableActivity($this->ceo);
    app(ActionInstructions::class)->send($activity, [InstructionKind::Tickets], null, $this->ceo);
    $instruction = $activity->instructions()->sole();

    $this->actingAs($this->procurementHead)->get(route('instructions.index'))->assertOk()->assertSee($activity->reference)->assertSee('Procure the travel tickets');
    $this->actingAs($this->procurementHead)->get(route('instructions.show', $instruction))->assertOk()
        ->assertSee('Wanjiru Example')
        ->assertSee('Air ticket Nairobi to Kisumu')
        ->assertDontSee('DSA')
        ->assertDontSee('Confidential reasons for the visit.');
    $this->actingAs($this->procurementHead)->get(route('memos.show', $activity))->assertForbidden();
    $this->actingAs($this->procurementHead)->get(route('memos.document', $activity))->assertForbidden();
    $this->actingAs($this->financeHead)->get(route('instructions.show', $instruction))->assertForbidden();
    $this->actingAs($this->otherHead)->get(route('instructions.show', $instruction))->assertForbidden();
});

it('shows the Instructions menu only to the Heads of the departments that receive instructions', function () {
    $this->actingAs($this->financeHead)->get(route('memos.index'))->assertSee(route('instructions.index'));
    $this->actingAs($this->otherHead)->get(route('memos.index'))->assertDontSee(route('instructions.index'));
    $this->actingAs($this->ceo)->get(route('dashboard'))->assertDontSee(route('instructions.index'));
});

it('is acknowledged and then completed with a reference, telling the Office of the CEO and the department', function () {
    $activity = instructableActivity($this->ceo);
    app(ActionInstructions::class)->send($activity, [InstructionKind::Imprest], null, $this->ceo);
    $instruction = $activity->instructions()->sole();
    $cos = User::query()->role(User::ROLE_CHIEF_OF_STAFF)->firstOrFail();

    $sheet = Livewire::actingAs($this->financeHead)->test(InstructionSheet::class, ['instruction' => $instruction])
        ->call('acknowledge')
        ->set('showComplete', true)
        ->call('complete')
        ->assertHasErrors(['reference' => 'required'])
        ->set('reference', 'IMP/2026/0457')
        ->call('complete')
        ->assertHasNoErrors();

    expect($instruction->fresh())
        ->status->toBe(InstructionStatus::Completed)
        ->acknowledged_by->toBe($this->financeHead->id)
        ->completed_by->toBe($this->financeHead->id)
        ->completion_reference->toBe('IMP/2026/0457');
    expect($this->ceo->notifications()->where('data->kind', 'instruction_completed')->count())->toBe(1)
        ->and($cos->notifications()->where('data->kind', 'instruction_completed')->count())->toBe(1);
    $sheet->set('reference', 'IMP/2026/0458')->call('complete')->assertDispatched('notify', message: 'This instruction is already completed.', type: 'error');
    expect(fn () => app(ActionInstructions::class)->acknowledge($instruction->fresh(), $this->procurementHead))->toThrow(AuthorizationException::class);
});

it('sends to the department chosen in Settings instead of the default', function () {
    $ict = Department::query()->where('name', 'ICT')->firstOrFail();
    app(AppSettings::class)->set('procurement_department_id', $ict->id);
    $activity = instructableActivity($this->ceo);

    $warnings = app(ActionInstructions::class)->send($activity, [InstructionKind::Tickets], null, $this->ceo);

    expect(ActionInstruction::query()->sole()->department_id)->toBe($ict->id)
        ->and($warnings)->toBe(['ICT has no active Head of Department yet; the instruction to procure the travel tickets waits until one is set up.']);
});
