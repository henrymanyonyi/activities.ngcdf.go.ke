<?php

namespace App\Livewire\Concerns;

use App\Enums\InstructionKind;
use App\Models\Activity;
use App\Services\ActionInstructions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The CEO's tick boxes "Instruct Finance" and "Instruct Procurement", on the
 * Approve popup and on an approved activity (App\Services\ActionInstructions).
 * Needs RunsActions.
 */
trait SendsInstructions
{
    /** @var list<string> InstructionKind values ticked */
    public array $instruct = [];

    public string $instructionNote = '';

    /** Sends the ticked instructions; true when there was nothing to send or they were sent. */
    protected function sendInstructions(Activity $activity): bool
    {
        if ($this->instruct === []) {
            return true;
        }

        $this->validate([
            'instruct' => ['array'],
            'instruct.*' => [Rule::enum(InstructionKind::class)],
            'instructionNote' => ['nullable', 'string', 'max:2000'],
        ], [], ['instructionNote' => 'note to Finance and Procurement']);

        $kinds = array_map(fn (string $kind) => InstructionKind::from($kind), $this->instruct);
        $warnings = $this->attempt(fn () => app(ActionInstructions::class)->send($activity, $kinds, $this->instructionNote, Auth::user()));

        if (! is_array($warnings)) {
            return false;
        }

        $this->toast(collect($kinds)->map->office()->implode(' and ').' instructed.');
        $this->warn($warnings);
        $this->reset(['instruct', 'instructionNote']);

        return true;
    }
}
