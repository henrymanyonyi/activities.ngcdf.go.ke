<?php

namespace App\Livewire\Instructions;

use App\Livewire\Concerns\RunsActions;
use App\Models\ActionInstruction;
use App\Services\AccessLogger;
use App\Services\ActionInstructions;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The instruction sheet a Head of Finance or Procurement works from: what the
 * CEO asks, the activity's reference, dates, places and team, and only the
 * cost lines they act on. Never the memo itself (its justification, reviews,
 * documents or budget position), which stays with its own department.
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use RunsActions;

    public ActionInstruction $instruction;

    public bool $showComplete = false;

    public string $reference = '';

    public string $note = '';

    public function mount(ActionInstruction $instruction, AccessLogger $log): void
    {
        // The instructed Head acts on it; the Office of the CEO (who sees everything) reads it.
        abort_unless($instruction->isFor(Auth::user()) || Auth::user()->can('activities.view'), 403);
        $this->instruction = $instruction;
        $log->log(AccessLogger::VIEW, $instruction->activity, ['page' => 'instruction', 'instruction' => $instruction->id]);
    }

    public function acknowledge(ActionInstructions $instructions): void
    {
        $this->attempt(fn () => $instructions->acknowledge($this->instruction, Auth::user()), 'Acknowledged. The Office of the CEO can see that you have it in hand.');
    }

    public function complete(ActionInstructions $instructions): void
    {
        $this->validate([
            'reference' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['reference' => strtolower($this->instruction->kind->referenceLabel())]);

        if ($this->attempt(fn () => $instructions->complete($this->instruction, $this->reference, $this->note, Auth::user()), 'Recorded as done. The Office of the CEO and the department have been told.')) {
            $this->reset(['showComplete', 'reference', 'note']);
        }
    }

    public function render(ActionInstructions $instructions): View
    {
        $this->instruction->load(['activity.organisingDepartment', 'activity.locations.county', 'activity.locations.region', 'activity.locations.constituency', 'activity.participants.staff', 'sender', 'acknowledger', 'completer', 'department']);
        $lines = $instructions->costLines($this->instruction);

        return view('livewire.instructions.show', [
            'i' => $this->instruction,
            'a' => $this->instruction->activity,
            'lines' => $lines,
            'acts' => $this->instruction->isFor(Auth::user()),
        ])->title($this->instruction->kind->office().' instruction · '.$this->instruction->activity->reference);
    }
}
