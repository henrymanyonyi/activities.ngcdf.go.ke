@use('App\Enums\ActivityStatus')
@use('App\Models\Activity')
@use('App\Models\Directive')
@php
    $user = auth()->user();
    $awaiting = Activity::query()->status(ActivityStatus::AwaitingDecision)->count();
    $drafts = Activity::query()->status(ActivityStatus::Draft, ActivityStatus::Returned)->count();
    $overdueDirectives = Directive::query()->overdue()->count();

    $sections = [
        '' => [
            ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'fa-gauge-high', 'label' => 'Dashboard', 'can' => 'activities.view'],
            ['route' => 'decisions.index', 'match' => 'decisions.*', 'icon' => 'fa-gavel', 'label' => 'Decision queue', 'can' => 'activities.view', 'count' => $awaiting],
            ['route' => 'activities.index', 'match' => 'activities.*', 'icon' => 'fa-table-list', 'label' => 'Activity register', 'can' => 'activities.view', 'count' => $user->can('activities.submit') ? $drafts : 0],
            ['route' => 'calendar', 'match' => 'calendar', 'icon' => 'fa-calendar-days', 'label' => 'Calendar', 'can' => 'activities.view'],
            ['route' => 'field-today', 'match' => 'field-today', 'icon' => 'fa-person-walking-luggage', 'label' => 'In the field today', 'can' => 'activities.view'],
            ['route' => 'directives.index', 'match' => 'directives.*', 'icon' => 'fa-list-check', 'label' => 'Directives', 'can' => 'activities.view', 'count' => $overdueDirectives],
        ],
        'Analysis' => [
            ['route' => 'participation', 'match' => 'participation', 'icon' => 'fa-users-viewfinder', 'label' => 'Staff participation', 'can' => 'activities.view'],
            ['route' => 'department-costs', 'match' => 'department-costs', 'icon' => 'fa-building-columns', 'label' => 'Department costs', 'can' => 'activities.view'],
            ['route' => 'reports.index', 'match' => 'reports.*', 'icon' => 'fa-file-lines', 'label' => 'Reports', 'can' => 'activities.view'],
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
    @php $visible = collect($items)->filter(fn ($item) => empty($item['hidden']) && $user->can($item['can']) && Route::has($item['route'])); @endphp
    @continue($visible->isEmpty())

    @if ($heading !== '')
        <p x-show="sidebarOpen" class="px-3 pt-5 pb-2 text-[10px] font-semibold uppercase tracking-wider text-emerald-400/70">{{ $heading }}</p>
        <div x-show="!sidebarOpen" class="my-3 border-t border-emerald-800/50"></div>
    @endif

    @foreach ($visible as $item)
        @php $active = request()->routeIs($item['match']); @endphp
        <a href="{{ route($item['route']) }}" wire:navigate title="{{ $item['label'] }}"
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
