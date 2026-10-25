<?php

namespace App\Livewire\Memos;

use App\Livewire\Concerns\RunsActions;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Department;
use App\Models\Staff;
use App\Models\SubmissionLink;
use App\Services\BudgetPosition;
use App\Services\DelegatedMemos;
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
 * holds it, what happens next and the latest comment. A Head of Department
 * also asks an officer to prepare a memo here, and follows those requests
 * (App\Services\DelegatedMemos).
 */
#[Layout('layouts.admin')]
#[Title('Activity memos')]
class Board extends Component
{
    use RunsActions;
    use WithPagination;

    // A memo request to an officer
    public bool $showRequest = false;

    public string $requestSearch = '';

    public ?int $requestStaffId = null;

    public string $requestPurpose = '';

    public int $requestDays = 7;

    /** The link just sent, shown once so the Head can also copy it. */
    public ?string $issuedUrl = null;

    public ?int $revokeId = null;

    public bool $showRevoke = false;

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

    public function openRequest(): void
    {
        abort_unless(Auth::user()->isHeadOfDepartment(), 403);
        $this->resetValidation();
        $this->reset(['requestSearch', 'requestStaffId', 'requestPurpose', 'issuedUrl']);
        $this->requestDays = 7;
        $this->showRequest = true;
    }

    public function pickRequestStaff(int $id): void
    {
        $this->requestStaffId = Staff::query()->active()->findOrFail($id)->id;
        $this->requestSearch = '';
    }

    public function sendRequest(DelegatedMemos $memos): void
    {
        $this->validate([
            'requestStaffId' => ['required', 'exists:staff,id'],
            'requestPurpose' => ['nullable', 'string', 'max:2000'],
            'requestDays' => ['required', 'integer', 'min:1', 'max:'.DelegatedMemos::MAX_DAYS],
        ], ['requestStaffId.required' => 'Choose the officer.'], ['requestPurpose' => 'instructions', 'requestDays' => 'days the link lasts']);

        $result = $this->attempt(fn () => $memos->request(Auth::user(), Staff::query()->findOrFail($this->requestStaffId), $this->requestPurpose, $this->requestDays));
        if (is_array($result)) {
            $this->issuedUrl = $result[1];
            $this->tab = 'from_staff';
            $this->toast('Memo request sent to '.$result[0]->recipient_name.' by email and SMS.');
        }
    }

    public function askRevoke(int $id): void
    {
        [$this->revokeId, $this->showRevoke] = [$id, true];
    }

    public function revokeRequest(DelegatedMemos $memos): void
    {
        $link = SubmissionLink::query()->delegated()->findOrFail($this->revokeId);
        $this->attempt(fn () => $memos->revoke($link, Auth::user()), 'Link withdrawn. It no longer opens.');
        [$this->revokeId, $this->showRevoke] = [null, false];
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
            'canRequest' => $user->isHeadOfDepartment(),
            'requests' => $tab === 'from_staff' && $user->isHeadOfDepartment() ? app(DelegatedMemos::class)->linksFor($user) : collect(),
            'requestMatches' => $this->showRequest && mb_strlen(trim($this->requestSearch)) >= 2
                ? Staff::query()->active()->search(trim($this->requestSearch))->with('department')->orderByRaw('department_id = ? desc', [$user->department_id])->orderBy('name')->limit(10)->get()
                : collect(),
            'requestPicked' => $this->requestStaffId ? Staff::query()->with('department')->find($this->requestStaffId) : null,
        ]);
    }
}
