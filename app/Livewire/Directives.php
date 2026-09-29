<?php

namespace App\Livewire;

use App\Livewire\Concerns\RunsActions;
use App\Models\Department;
use App\Models\Directive;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** FRD CD-04: CEO directives and action items, tracked to completion by the Chief of Staff. */
#[Layout('layouts.admin')]
#[Title('Directives')]
class Directives extends Component
{
    use RunsActions;
    use WithPagination;

    #[Url(except: 'open')]
    public string $status = 'open';

    public bool $showNew = false;

    public bool $showComplete = false;

    public ?int $directiveId = null;

    public string $body = '';

    public string $department = '';

    public string $due = '';

    public string $note = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        abort_unless(Auth::user()->can('directives.manage'), 403);
        $this->validate(['body' => ['required', 'string', 'max:2000'], 'department' => ['nullable', 'exists:departments,id'], 'due' => ['nullable', 'date']], [], ['body' => 'directive']);

        Directive::create([
            'body' => $this->body,
            'responsible_department_id' => $this->department ?: null,
            'due_on' => $this->due ?: null,
            'status' => Directive::STATUS_OPEN,
            'on_ceo_instruction' => ! Auth::user()->can('activities.decide'),
            'issued_by' => Auth::id(),
        ]);

        $this->reset(['showNew', 'body', 'department', 'due']);
        $this->toast('Directive recorded.');
    }

    public function openComplete(int $id): void
    {
        $this->directiveId = $id;
        $this->note = '';
        $this->showComplete = true;
    }

    public function complete(): void
    {
        abort_unless(Auth::user()->can('directives.manage'), 403);
        $this->validate(['note' => ['required', 'string', 'max:2000']], [], ['note' => 'completion note']);

        Directive::query()->open()->findOrFail($this->directiveId)->update([
            'status' => Directive::STATUS_COMPLETED,
            'completion_note' => $this->note,
            'completed_at' => now(),
            'completed_by' => Auth::id(),
        ]);

        $this->reset(['showComplete', 'directiveId', 'note']);
        $this->toast('Directive marked complete.');
    }

    public function render(): View
    {
        $directives = Directive::query()
            ->when($this->status === 'open', fn ($q) => $q->open())
            ->when($this->status === 'overdue', fn ($q) => $q->overdue())
            ->when($this->status === 'completed', fn ($q) => $q->where('status', Directive::STATUS_COMPLETED))
            ->with(['activity', 'department', 'issuer'])
            ->orderByRaw('due_on is null')->orderBy('due_on')->latest('id')
            ->paginate(25);

        return view('livewire.directives', [
            'directives' => $directives,
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'tabs' => [
                'open' => ['icon' => 'fa-list-check', 'label' => 'Open', 'count' => Directive::query()->open()->count()],
                'overdue' => ['icon' => 'fa-triangle-exclamation', 'label' => 'Overdue', 'count' => Directive::query()->overdue()->count()],
                'completed' => ['icon' => 'fa-check', 'label' => 'Completed', 'count' => 0],
                'all' => ['icon' => 'fa-layer-group', 'label' => 'All', 'count' => 0],
            ],
        ]);
    }
}
