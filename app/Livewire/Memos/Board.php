<?php

namespace App\Livewire\Memos;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Department;
use App\Services\BudgetPosition;
use App\Services\MemoTracker;
use App\Support\FinancialYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Activity memos for the signed-in user: a Head of Department sees their own
 * department's, the Chief of Staff and Assistant what is waiting for review,
 * and the CEO what is waiting for a decision. Each memo shows its status, who
 * holds it, what happens next and the latest comment.
 */
#[Layout('layouts.admin')]
#[Title('Activity memos')]
class Board extends Component
{
    use WithPagination;

    public const FILTERS = ['search', 'department_id', 'activity_type_id', 'from', 'to'];

    #[Url(except: '')]
    public string $tab = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $department_id = '';

    #[Url(except: '')]
    public string $activity_type_id = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, [...self::FILTERS, 'tab'], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return collect(self::FILTERS)->contains(fn (string $f) => $this->{$f} !== '');
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function filtered(Builder $query): Builder
    {
        return $query
            ->search($this->search)
            ->when($this->department_id, fn (Builder $q, $id) => $q->where('organising_department_id', $id))
            ->when($this->activity_type_id, fn (Builder $q, $id) => $q->where('activity_type_id', $id))
            ->when($this->from, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($this->to, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
    }

    public function render(MemoTracker $tracker, BudgetPosition $budget): View
    {
        $user = Auth::user();
        $tabs = $tracker->tabs($user);
        abort_if($tabs === [], 403);

        $tab = array_key_exists($this->tab, $tabs) ? $this->tab : array_key_first($tabs);
        $perspective = $tracker->perspective($user);

        $memos = $this->filtered($tracker->query($user, $tab))
            ->with(['organisingDepartment', 'type', 'creator', 'responsibleHod', 'reviewNotes.user', 'statusHistory.user'])
            ->latest('updated_at')
            ->paginate(15);

        return view('livewire.memos.board', [
            'tabs' => $tabs,
            'current' => $tab,
            'perspective' => $perspective,
            'counts' => collect($tabs)->map(fn ($t, string $key) => $this->filtered($tracker->query($user, $key))->count()),
            'memos' => $memos,
            'movedAt' => $tracker->lastMoves($memos->getCollection()->pluck('id')),
            'summary' => $perspective === MemoTracker::PERSPECTIVE_DEPARTMENT ? null : $tracker->summary($this->filtered(Activity::query()->visibleTo($user))),
            'departments' => $perspective === MemoTracker::PERSPECTIVE_DEPARTMENT ? collect() : Department::query()->orderBy('name')->get(['id', 'name']),
            'types' => ActivityType::query()->orderBy('name')->get(['id', 'name']),
            'department' => $perspective === MemoTracker::PERSPECTIVE_DEPARTMENT ? $user->department : null,
            // A Head of Department sees their own department's budget for the year before preparing a memo.
            'budget' => $perspective === MemoTracker::PERSPECTIVE_DEPARTMENT && $user->department_id ? $budget->forDepartment(FinancialYear::current(), $user->department_id) : null,
        ]);
    }
}
