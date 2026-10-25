@use('App\Enums\ActivityStatus')
@use('App\Models\Activity')
@use('App\Models\Directive')
@use('App\Enums\BudgetStatus')
@use('App\Models\BudgetAdjustment')
@use('App\Models\DepartmentBudget')
@use('App\Services\MemoTracker')
@use('App\Services\ActionInstructions')
@use('App\Models\ActionInstruction')
@use('App\Enums\InstructionStatus')
@php
    $user = auth()->user();
    $officeView = $user->can('activities.view');
    $awaiting = $officeView ? Activity::query()->status(ActivityStatus::AwaitingDecision)->count() : 0;
    // A Head of Department's own unsubmitted draft is not the Office of the CEO's to act on.
    $drafts = $officeView ? Activity::query()->where(fn ($q) => $q->status(ActivityStatus::Returned)
        ->orWhere(fn ($d) => $d->status(ActivityStatus::Draft)->where(fn ($o) => $o->whereNull('responsible_hod_id')->orWhereColumn('responsible_hod_id', '!=', 'created_by'))))->count() : 0;
    $overdueDirectives = $officeView ? Directive::query()->overdue()->count() : 0;
    $memoActions = app(MemoTracker::class)->actionCount($user);
    // The Heads of Finance and Procurement: the CEO's instructions still open.
    $receivesInstructions = app(ActionInstructions::class)->receives($user);
    $openInstructions = $receivesInstructions ? ActionInstruction::query()->for($user)->where('status', '!=', InstructionStatus::Completed->value)->count() : 0;
    // Budgeting: what awaits the CEO's approval, or what the CEO returned to the preparers.
    $budgetWaiting = $user->can('budgets.approve') ? BudgetStatus::Submitted : BudgetStatus::Returned;
    $budgetCount = $user->canAny(['budgets.prepare', 'budgets.approve']) ? DepartmentBudget::query()->where('status', $budgetWaiting->value)->count() : 0;
    $adjustmentCount = $user->canAny(['budgets.prepare', 'budgets.approve']) ? BudgetAdjustment::query()->where('status', $budgetWaiting->value)->count() : 0;
    $budgetLens = request()->routeIs('department-costs') && request('lens') === 'budget';

    $sections = [
        '' => [
            ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'fa-gauge-high', 'label' => 'Dashboard', 'can' => 'activities.view'],
            ['route' => 'memos.index', 'match' => 'memos.*', 'icon' => 'fa-envelope-open-text', 'label' => 'Activity memos', 'can' => ['memos.originate', 'memos.review'], 'count' => $memoActions],
            ['route' => 'instructions.index', 'match' => 'instructions.*', 'icon' => 'fa-clipboard-check', 'label' => 'Instructions', 'can' => 'instructions.action', 'hidden' => ! $receivesInstructions, 'count' => $openInstructions],
            // A Head of Department's own entry; the Office of the CEO finds the staff list under its section below.
            ['route' => 'staff.index', 'match' => 'staff.*', 'icon' => 'fa-id-badge', 'label' => 'Staff list', 'can' => 'staff.add', 'hidden' => $officeView],
            ['route' => 'decisions.index', 'match' => 'decisions.*', 'icon' => 'fa-gavel', 'label' => 'Decision queue', 'can' => 'activities.view', 'count' => $awaiting],
            ['route' => 'activities.index', 'match' => 'activities.*', 'icon' => 'fa-table-list', 'label' => 'Activity register', 'can' => 'activities.view', 'count' => $user->can('activities.submit') ? $drafts : 0],
            ['route' => 'calendar', 'match' => 'calendar', 'icon' => 'fa-calendar-days', 'label' => 'Calendar', 'can' => 'activities.view'],
            ['route' => 'field-today', 'match' => 'field-today', 'icon' => 'fa-person-walking-luggage', 'label' => 'In the field today', 'can' => 'activities.view'],
            ['route' => 'directives.index', 'match' => 'directives.*', 'icon' => 'fa-list-check', 'label' => 'Directives', 'can' => 'activities.view', 'count' => $overdueDirectives],
        ],
        'Analysis' => [
            ['route' => 'participation', 'match' => 'participation', 'icon' => 'fa-users-viewfinder', 'label' => 'Staff participation', 'can' => 'activities.view'],
            ['route' => 'department-costs', 'match' => 'department-costs', 'active' => ! $budgetLens && request()->routeIs('department-costs'), 'icon' => 'fa-building-columns', 'label' => 'Department costs', 'can' => 'activities.view'],
            ['route' => 'reports.index', 'match' => 'reports.*', 'icon' => 'fa-file-lines', 'label' => 'Reports', 'can' => 'activities.view'],
        ],
        'Budgeting' => [
            ['route' => 'budgets.index', 'match' => ['budgets.index', 'budgets.show'], 'icon' => 'fa-wallet', 'label' => 'Department budgets', 'can' => ['budgets.prepare', 'budgets.approve'], 'count' => $budgetCount],
            ['route' => 'budgets.adjustments', 'match' => 'budgets.adjustments', 'icon' => 'fa-sliders', 'label' => 'Budget adjustments', 'can' => ['budgets.prepare', 'budgets.approve'], 'count' => $adjustmentCount],
            ['route' => 'department-costs', 'params' => ['lens' => 'budget'], 'match' => 'department-costs', 'active' => $budgetLens, 'icon' => 'fa-scale-balanced', 'label' => 'Budget position', 'can' => 'activities.view'],
        ],
        'Office of the CEO' => [
            ['route' => 'links.index', 'match' => 'links.*', 'icon' => 'fa-link', 'label' => 'Submission links', 'can' => 'links.manage', 'hidden' => ! config('activities.submission_links')],
            ['route' => 'staff.index', 'match' => 'staff.*', 'icon' => 'fa-id-badge', 'label' => 'Staff list', 'can' => 'reference.manage'],
            ['route' => 'settings.reference', 'match' => 'settings.*', 'icon' => 'fa-sliders', 'label' => 'Reference data', 'can' => 'reference.manage'],
            ['route' => 'users.index', 'match' => 'users.*', 'icon' => 'fa-users-gear', 'label' => 'User accounts', 'can' => 'users.manage'],
            ['route' => 'audit.index', 'match' => 'audit.*', 'icon' => 'fa-clock-rotate-left', 'label' => 'Audit and access log', 'can' => 'audit.view'],
        ],
    ];
@endphp

@foreach ($sections as $heading => $items)
    @php $visible = collect($items)->filter(fn ($item) => empty($item['hidden']) && $user->canAny((array) $item['can']) && Route::has($item['route'])); @endphp
    @continue($visible->isEmpty())

    @if ($heading !== '')
        <p x-show="sidebarOpen" class="px-3 pt-5 pb-2 text-[10px] font-semibold uppercase tracking-wider text-emerald-400/70">{{ $heading }}</p>
        <div x-show="!sidebarOpen" class="my-3 border-t border-emerald-800/50"></div>
    @endif

    @foreach ($visible as $item)
        @php $active = $item['active'] ?? request()->routeIs(...(array) $item['match']); @endphp
        <a href="{{ route($item['route'], $item['params'] ?? []) }}" wire:navigate title="{{ $item['label'] }}"
            @if ($active) aria-current="page" @endif
            class="group flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13px] font-medium transition {{ $active ? 'bg-emerald-800 text-white' : 'text-emerald-100/80 hover:bg-emerald-800/60 hover:text-white' }}">
            <i class="fa-solid {{ $item['icon'] }} w-5 text-center text-sm {{ $active ? 'text-emerald-300' : 'text-emerald-400/80 group-hover:text-emerald-300' }}" aria-hidden="true"></i>
            <span x-show="sidebarOpen" class="flex-1 truncate">{{ $item['label'] }}</span>
            @if (($item['count'] ?? 0) > 0)
                <span x-show="sidebarOpen" class="font-numeric px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-400 text-emerald-950">{{ $item['count'] }}</span>
            @endif
        </a>
    @endforeach
@endforeach
