<?php

namespace App\Livewire;

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Livewire\Concerns\RunsActions;
use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Services\ActivityLifecycle;
use App\Services\ParticipantChecks;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** FRD CD-01 / CD-02: everything awaiting the CEO, with the facts needed to decide on one screen. */
#[Layout('layouts.admin')]
#[Title('Decision queue')]
class DecisionQueue extends Component
{
    use RunsActions;

    public ?int $activityId = null;

    public string $decision = 'approved';

    public string $comment = '';

    public bool $showDecide = false;

    public function open(int $id, string $decision): void
    {
        $this->activityId = $id;
        $this->decision = DecisionType::from($decision)->value;
        $this->comment = '';
        $this->resetErrorBag();
        $this->showDecide = true;
    }

    public function decide(ActivityLifecycle $lifecycle): void
    {
        $type = DecisionType::from($this->decision);
        $this->validate(['comment' => [$type->requiresComment() ? 'required' : 'nullable', 'string', 'max:2000']]);
        $activity = Activity::query()->findOrFail($this->activityId);

        if ($this->attempt(fn () => $lifecycle->decide($activity, $type, $this->comment, Auth::user()), "{$activity->reference}: {$type->label()}.")) {
            $this->reset(['showDecide', 'activityId', 'comment']);
        }
    }

    public function render(ParticipantChecks $checks): View
    {
        $items = Activity::query()
            ->status(ActivityStatus::AwaitingDecision)
            ->with(['organisingDepartment', 'type', 'participants.staff', 'locations.county', 'locations.region', 'locations.constituency', 'amendments', 'documents'])
            ->orderBy('start_date')
            ->get()
            ->map(fn (Activity $a) => [
                'activity' => $a,
                'conflicts' => $checks->conflictsFor($a),
                'overLimit' => $a->participants->where('is_external', false)->filter(function (ActivityParticipant $p) use ($checks, $a) {
                    $f = $checks->fieldDayFlags($p->staff_id, $a, $p->plannedDays($a));

                    return $f['over_quarter'] || $f['over_year'];
                }),
                'returnedAmendment' => $a->amendments->firstWhere('returned_for_decision', true),
            ]);

        return view('livewire.decision-queue', [
            'items' => $items,
            'selected' => $this->activityId ? $items->firstWhere('activity.id', $this->activityId)['activity'] ?? null : null,
        ]);
    }
}
