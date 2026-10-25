<?php

namespace App\Livewire\Instructions;

use App\Enums\InstructionStatus;
use App\Models\ActionInstruction;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The CEO's instructions addressed to the Head of Finance or Procurement: to
 * process the imprest or buy the tickets for an approved memo. Only their own
 * department's (ActionInstruction::scopeFor); each opens an instruction sheet.
 */
#[Layout('layouts.admin')]
#[Title('Instructions')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: 'open')]
    public string $tab = 'open';

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'completed' ? 'completed' : 'open';
        $this->resetPage();
    }

    public function render(): View
    {
        $user = Auth::user();
        $base = fn () => ActionInstruction::query()->for($user);

        return view('livewire.instructions.index', [
            'instructions' => $base()
                ->when($this->tab === 'completed',
                    fn ($q) => $q->where('status', InstructionStatus::Completed->value)->latest('completed_at'),
                    fn ($q) => $q->where('status', '!=', InstructionStatus::Completed->value)->orderBy('sent_at'))
                ->with(['activity.organisingDepartment'])
                ->paginate(30),
            'openCount' => $base()->where('status', '!=', InstructionStatus::Completed->value)->count(),
        ]);
    }
}
