{{--
    The generated activity memo and its annexes (App\Services\MemoDocument),
    laid out like the Board's internal memo template. Each annex starts on its
    own page. No RESTRICTED banner at the top (removed at the business
    owner's request, 21 October 2026); "Page n of N" is stamped by MemoDocument::pdf() once the pages exist.
--}}
@use('App\Support\DocumentFont')
@use('App\Support\Money')
@php
    $a = $activity;
    $kes = fn (int $cents) => Money::format($cents);
    $para = fn (?string $text) => nl2br(e(trim((string) $text)));
    $logo = public_path('images/ngcdf-logo.png');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $a->reference }} · Activity memo · RESTRICTED</title>
    <style>
        {!! DocumentFont::faces() !!}
        @page { margin: 48px 48px 60px 48px; }
        body { font-family: {!! DocumentFont::STACK !!}; font-size: 10px; color: #1e293b; line-height: 1.45; }
        footer.foot { position: fixed; left: 0; right: 0; bottom: -40px; font-size: 8px; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 4px; }
        .brand { text-align: center; margin-bottom: 6px; }
        .brand img { height: 58px; }
        .brand .org { font-size: 11px; font-weight: bold; color: #065f46; margin-top: 4px; letter-spacing: .5px; }
        .brand .kind { font-size: 13px; font-weight: bold; letter-spacing: 3px; margin-top: 6px; }
        .draft { border: 1px solid #f59e0b; background: #fffbeb; color: #92400e; padding: 5px 8px; margin: 6px 0 8px; font-size: 9px; }
        table.head { width: 100%; border-collapse: collapse; margin: 8px 0 4px; border-bottom: 2px solid #065f46; }
        table.head td { padding: 3px 0; vertical-align: top; }
        table.head td.k { width: 70px; font-weight: bold; }
        table.head td.v { text-transform: uppercase; }
        h2 { font-size: 10.5px; margin: 14px 0 4px; color: #065f46; }
        p { margin: 0 0 6px; text-align: justify; }
        table.sign { width: 100%; border-collapse: collapse; margin-top: 16px; page-break-inside: avoid; }
        table.sign td { padding: 9px 0 0; vertical-align: bottom; }
        table.sign .lab { font-weight: bold; width: 82px; vertical-align: top; }
        table.sign .line { color: #94a3b8; }
        table.sign .des { color: #475569; font-size: 9px; }
        .annex { page-break-before: always; }
        .annex h1 { font-size: 13px; margin: 0 0 2px; color: #065f46; }
        .annex .sub { color: #64748b; margin-bottom: 10px; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.grid th { background: #ecfdf5; color: #065f46; text-align: left; padding: 5px 5px; font-size: 8px; text-transform: uppercase; border: 1px solid #a7f3d0; }
        table.grid td { padding: 4px 5px; border: 1px solid #e2e8f0; vertical-align: top; }
        table.grid .num { text-align: right; white-space: nowrap; }
        table.grid tr.total td { font-weight: bold; background: #f8fafc; border-top: 2px solid #065f46; }
        table.grid tr { page-break-inside: avoid; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    <footer class="foot">{{ $a->reference }} · {{ Str::limit($a->title, 90) }}</footer>

    {{-- ── The memo ─────────────────────────────────────────────── --}}
    <div class="brand">
        @if (is_file($logo))<img src="{{ $logo }}" alt="NG-CDF Board">@endif
        <div class="org">NATIONAL GOVERNMENT CONSTITUENCIES DEVELOPMENT FUND BOARD</div>
        <div class="kind">INTERNAL MEMO</div>
    </div>

    @if ($isDraft)
        <div class="draft"><strong>DRAFT.</strong> Not yet submitted for review. Figures and team may still change.</div>
    @endif

    <table class="head">
        <tr><td class="k">TO:</td><td class="v">{{ $to }}</td></tr>
        <tr><td class="k">FROM:</td><td class="v">{{ $from }}</td></tr>
        <tr><td class="k">DATE:</td><td class="v">{{ $date->format('jS F, Y') }}</td></tr>
        <tr><td class="k">REF NO:</td><td class="v">{{ $refNo }}</td></tr>
        <tr><td class="k">SUBJECT:</td><td class="v"><strong>{{ $a->title }}</strong></td></tr>
    </table>

    @foreach ($sections as $section)
        <h2>{{ $section['number'] }} {{ $section['heading'] }}</h2>
        @foreach ($section['paragraphs'] as $text)
            @if (trim($text) === '')
                <p class="muted"><em>Not provided.</em></p>
            @else
                <p>{!! $para($text) !!}</p>
            @endif
        @endforeach
    @endforeach

    <h2>{{ count($sections) + 1 }}.0 Request for Approval</h2>
    <p>{{ $request }}</p>
    <p>Therefore, this is to seek your authority for the above activity and the accompanying budget, to enable achievement of the intended activity.</p>

    <table class="sign">
        @foreach ($signatures as $s)
            <tr>
                <td class="lab">{{ $s['label'] }}:</td>
                <td>
                    Name: {{ $s['name'] ?? '' }}@if (! $s['name'])<span class="line">……………………………………</span>@endif
                    &nbsp; Signature: <span class="line">……………………………</span>
                    &nbsp; Date: @if ($s['date']){{ $s['date']->format('d M Y') }}@else<span class="line">………………</span>@endif
                    <div class="des">{{ $s['designation'] }}</div>
                </td>
            </tr>
        @endforeach
    </table>

    {{-- ── Annex A: budget breakdown ─────────────────────────────── --}}
    <div class="annex">
        <h1>Annex A: Budget Breakdown</h1>
        <div class="sub">{{ $a->reference }} · {{ $a->title }} · total requested KES {{ $kes($total) }}</div>

        <table class="grid">
            <thead><tr><th>Cost item</th><th class="num">Amount (KES)</th><th class="num">Share</th></tr></thead>
            <tbody>
                @forelse ($byCategory as $r)
                    <tr><td>{{ $r['label'] }}</td><td class="num">{{ $kes($r['amount']) }}</td><td class="num">{{ $r['share'] }}%</td></tr>
                @empty
                    <tr><td colspan="3" class="muted">No cost lines have been entered.</td></tr>
                @endforelse
                <tr class="total"><td>Total</td><td class="num">{{ $kes($total) }}</td><td class="num">{{ $total > 0 ? '100%' : '' }}</td></tr>
            </tbody>
        </table>

        <table class="grid">
            <thead><tr><th>Department</th><th class="num">People</th><th class="num">Amount (KES)</th><th class="num">Share</th></tr></thead>
            <tbody>
                @forelse ($byDepartment as $r)
                    <tr><td>{{ $r['label'] }}</td><td class="num">{{ $r['people'] ?? '' }}</td><td class="num">{{ $kes($r['amount']) }}</td><td class="num">{{ $r['share'] }}%</td></tr>
                @empty
                    <tr><td colspan="4" class="muted">No cost lines have been entered.</td></tr>
                @endforelse
                <tr class="total"><td>Total</td><td></td><td class="num">{{ $kes($total) }}</td><td class="num">{{ $total > 0 ? '100%' : '' }}</td></tr>
            </tbody>
        </table>
        <p class="muted">Each officer's own costs are shown under their department; venue, fuel, materials and other costs of the whole activity are shown as shared. Totals agree with Annex B.</p>
    </div>

    {{-- ── Annex B: detailed budget ──────────────────────────────── --}}
    <div class="annex">
        <h1>Annex B: Detailed Activity Budget</h1>
        <div class="sub">{{ $a->days }} {{ Str::plural('day', $a->days) }}, {{ $a->nights }} {{ Str::plural('night', $a->nights) }} · {{ $a->start_date->format('d M Y') }} to {{ $a->end_date->format('d M Y') }} · {{ $a->locationSummary() ?: 'Venue not stated' }}</div>

        <table class="grid">
            <thead><tr><th>Item description</th><th>Category</th><th>Scale</th><th class="num">Pax</th><th class="num">Days / qty</th><th class="num">Rate (KES)</th><th class="num">Amount (KES)</th></tr></thead>
            <tbody>
                @forelse ($detailed as $r)
                    <tr>
                        <td>{{ $r['item'] }}</td><td>{{ $r['category'] }}</td><td>{{ $r['scale'] }}</td>
                        <td class="num">{{ $r['pax'] ?? '' }}</td><td class="num">{{ $r['days'] ?? '' }}</td>
                        <td class="num">{{ $r['rate'] !== null ? $kes($r['rate']) : '' }}</td><td class="num">{{ $kes($r['amount']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No cost lines have been entered.</td></tr>
                @endforelse
                <tr class="total"><td colspan="6">Total</td><td class="num">{{ $kes($total) }}</td></tr>
            </tbody>
        </table>
        <p class="muted">DSA is calculated from each officer's scale (job grade) and their own nights, at the rate in force on the start date. "(adjusted)" marks a DSA amount changed by the Office of the CEO with a recorded reason.</p>
    </div>

    {{-- ── Annex C: participant itinerary ────────────────────────── --}}
    <div class="annex">
        <h1>Annex C: Participant Itinerary</h1>
        <div class="sub">{{ $a->title }} · {{ $a->locationSummary() ?: 'Venue not stated' }}</div>

        <table class="grid">
            <thead><tr><th class="num">No.</th><th>Name</th><th>PF no.</th><th>Designation</th><th>Department</th><th>Role</th><th>From</th><th>To</th><th class="num">Days</th><th>Travel</th></tr></thead>
            <tbody>
                @forelse ($itinerary as $i => $r)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>{{ $r['name'] }}@if ($r['note'])<br><span class="muted">{{ $r['note'] }}</span>@endif</td>
                        <td>{{ $r['number'] }}</td><td>{{ $r['designation'] }}</td><td>{{ $r['department'] }}</td><td>{{ $r['role'] }}</td>
                        <td>{{ $r['from']->format('d M Y') }}</td><td>{{ $r['to']->format('d M Y') }}</td><td class="num">{{ $r['days'] }}</td><td>{{ $r['travel'] ?? '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="muted">No participants have been added.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
