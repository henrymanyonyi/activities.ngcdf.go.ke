<?php

namespace App\Livewire\Concerns;

use App\Enums\InstructionKind;
use App\Models\Activity;
use App\Services\ActionInstructions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The CEO's instructions to the departments that act on an approved memo, on
 * the Approve popup and on an approved activity (App\Services\ActionInstructions):
 * tick boxes for Finance (imprest) and Procurement (tickets), and rows for any
 * other department with what it must do. Needs RunsActions.
 */
trait SendsInstructions
{
    /** @var list<string> InstructionKind values ticked (Finance, Procurement) */
    public array $instruct = [];

    public string $instructionNote = '';

    /** @var list<array{department_id: string, instruction: string}> other departments and what each must do */
    public array $otherInstructions = [];

    public function addOtherInstruction(): void
    {
        if (count($this->otherInstructions) < 10) {
            $this->otherInstructions[] = ['department_id' => '', 'instruction' => ''];
        }
    }

    public function removeOtherInstruction(int $index): void
    {
        unset($this->otherInstructions[$index]);
        $this->otherInstructions = array_values($this->otherInstructions);
    }

    protected function hasInstructions(): bool
    {
        return $this->instruct !== [] || $this->otherInstructions !== [];
    }

    /** Checks the rows before the CEO approves, so an incomplete instruction never leaves an approval without it. */
    protected function validateInstructions(): void
    {
        if (! $this->hasInstructions()) {
            return;
        }

        $this->validate([
            'instruct' => ['array'],
            'instruct.*' => [Rule::enum(InstructionKind::class)],
            'instructionNote' => ['nullable', 'string', 'max:2000'],
            'otherInstructions' => ['array', 'max:10'],
            'otherInstructions.*.department_id' => ['required', 'distinct', Rule::exists('departments', 'id')->where('is_active', true)],
            'otherInstructions.*.instruction' => ['required', 'string', 'max:2000'],
        ], [
            'otherInstructions.*.department_id.required' => 'Choose the department.',
            'otherInstructions.*.department_id.distinct' => 'Each department once.',
            'otherInstructions.*.instruction.required' => 'Write what the department must do.',
        ], ['instructionNote' => 'note to Finance and Procurement']);
    }

    /** Sends the instructions; true when there was nothing to send or they were sent. */
    protected function sendInstructions(Activity $activity): bool
    {
        if (! $this->hasInstructions()) {
            return true;
        }

        $this->validateInstructions();

        $kinds = array_map(fn (string $kind) => InstructionKind::from($kind), $this->instruct);
        $warnings = $this->attempt(fn () => app(ActionInstructions::class)->send($activity, $kinds, $this->instructionNote, Auth::user(), $this->otherInstructions));

        if (! is_array($warnings)) {
            return false;
        }

        $count = count($kinds) + count($this->otherInstructions);
        $this->toast($count === 1 ? 'Instruction sent.' : "{$count} instructions sent.");
        $this->warn($warnings);
        $this->reset(['instruct', 'instructionNote', 'otherInstructions']);

        return true;
    }
}
