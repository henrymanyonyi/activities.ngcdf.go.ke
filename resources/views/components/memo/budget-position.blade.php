{{--
    The requesting department's budget position for the activity's financial
    year (App\Services\BudgetPosition::forActivity), and the balance if this
    activity's plan is approved. Shown to the Office of the CEO on the activity
    page and in the CEO's approval popup.
--}}
@props(['position'])
@use('App\Support\Ui')
@use('App\Support\Money')
@php
    $p = $position;
    $kes = fn (?int $cents) => $cents === null ? '—' : 'KES '.Money::format($cents);
    $cell = 'rounded-lg border border-slate-200 bg-white px-3 py-2.5';
    $after = $p['balance_after'];
@endphp

<div {{ $attributes->merge(['class' => 'space-y-2.5']) }}>
    <p class="{{ Ui::MICRO }}">Budget position · {{ $p['department']?->name ?? 'No department' }} · FY {{ $p['financial_year'] }}</p>
    @if ($p['allocation'] === null)
        <p class="text-[13px] text-amber-700"><i class="fa-solid fa-circle-info mr-1"></i>No approved budget is recorded for this department and year, so the balance cannot be shown. Budgets are prepared under Budgeting and count once the CEO approves them.</p>
    @endif
    <div class="grid grid-cols-2 gap-2.5">
        <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Allocation</p><p class="text-[13px] font-bold font-numeric">{{ $kes($p['allocation']) }}</p></div>
        <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Spent</p><p class="text-[13px] font-bold font-numeric">{{ $kes($p['spent']) }}</p><p class="text-[10px] text-slate-400">from Finance returns</p></div>
        <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Committed</p><p class="text-[13px] font-bold font-numeric">{{ $kes($p['committed']) }}</p><p class="text-[10px] text-slate-400">{{ $p['approved_count'] }} approved {{ Str::plural('activity', $p['approved_count']) }}, not yet spent</p></div>
        <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Available balance</p><p class="text-[13px] font-bold font-numeric {{ ($p['balance'] ?? 0) < 0 ? 'text-red-600' : '' }}">{{ $kes($p['balance']) }}</p>@if ($p['utilisation'] !== null)<p class="text-[10px] text-slate-400">{{ $p['utilisation'] }}% used</p>@endif</div>
        <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Other pending requests</p><p class="text-[13px] font-bold font-numeric text-purple-700">{{ $kes($p['other_pending']) }}</p><p class="text-[10px] text-slate-400">not deducted</p></div>
        <div class="{{ $cell }} {{ $after !== null && $after < 0 ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50' }}">
            <p class="{{ Ui::MICRO }}">{{ $p['already_counted'] ? 'This activity' : 'Balance if approved' }}</p>
            <p class="text-[13px] font-bold font-numeric {{ $after !== null && $after < 0 ? 'text-red-700' : '' }}">{{ $p['already_counted'] ? $kes($p['this_request']) : $kes($after) }}</p>
            <p class="text-[10px] text-slate-400">{{ $p['already_counted'] ? 'already in committed and spent' : 'after this request of '.$kes($p['this_request']) }}</p>
        </div>
    </div>
    @if ($after !== null && $after < 0 && ! $p['already_counted'])
        <p class="text-[13px] text-red-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Approving this request would take the department {{ $kes(-$after) }} over its allocation for the year.</p>
    @endif
</div>
