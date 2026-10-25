{{--
    The generated activity memo (App\Services\MemoDocument), in the layout of the
    Board's internal memo template (files/MEMO Template .docx): US Letter, the
    template's margins, black text, bold TO / FROM / DATE / REF NO / SUBJECT, numbered
    section headings, the request for approval, the four signature blocks, then
    Annex: A (Activity Budget Request, with the signatures repeated) and the
    Participants table. "Page n of N" is stamped by MemoDocument::pdf().
    The font is Gill Sans MT (App\Support\DocumentFont); the template itself uses Trebuchet MS.
--}}
@use('App\Support\DocumentFont')
@use('App\Support\Money')
@php
    $a = $activity;
    $kes = fn (int $cents) => number_format(intdiv($cents, 100)).($cents % 100 ? '.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) : '');
    $para = fn (?string $text) => nl2br(e(trim((string) $text)));
    $logo = public_path('images/ngcdf-logo.png');
    $upper = fn (?string $text) => mb_strtoupper((string) $text);
    // Rows of the budget table that share an item description are merged in its first column, as in the template.
    $spans = [];
    foreach ($detailed as $i => $r) {
        $first = $i === 0 || $detailed[$i - 1]['item'] !== $r['item'];
        $spans[$i] = $first ? collect(array_slice($detailed, $i))->takeWhile(fn ($x) => $x['item'] === $r['item'])->count() : 0;
    }
    $datesDiffer = collect($itinerary)->contains(fn ($p) => ! $p['from']->equalTo($a->start_date) || ! $p['to']->equalTo($a->end_date));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $a->reference }} · Internal memo · RESTRICTED</title>
    <style>
        {!! DocumentFont::faces() !!}
        /* The template's margins: top 0.39in, right 0.43in, bottom 1in, left 1in. */
        @page { margin: 28pt 31pt 72pt 72pt; }
        body { font-family: {!! DocumentFont::STACK !!}; font-size: 11pt; color: #000; line-height: 1.3; }
        table.brand { width: 100%; border-collapse: collapse; border-bottom: 2pt solid #000; margin-bottom: 8pt; }
        table.brand td { vertical-align: middle; padding: 0 0 6pt; }
        table.brand td.logo { width: 92pt; }
        table.brand img { height: 66pt; }
        table.brand td.org { text-align: center; font-size: 15pt; letter-spacing: .3pt; line-height: 1.6; }
        .kind { text-align: center; font-weight: bold; font-size: 15pt; text-decoration: underline; margin: 6pt 0 10pt; }
        .draft { text-align: center; font-size: 9pt; margin: -6pt 0 8pt; }
        table.head { width: 100%; border-collapse: collapse; border-bottom: 1.5pt solid #000; margin: 0 0 8pt 26pt; }
        table.head td { padding: 0 0 9pt; vertical-align: top; font-weight: bold; font-size: 12pt; }
        table.head td.k { width: 78pt; }
        table.head td.v { text-transform: uppercase; text-align: justify; }
        h2 { font-size: 12pt; font-weight: bold; margin: 12pt 0 6pt; }
        p { margin: 0 0 8pt; text-align: justify; }
        ul.bullets { margin: 0 0 8pt 18pt; padding: 0; }
        ul.bullets li { margin: 0 0 3pt; text-align: justify; }
        .sign { margin-top: 14pt; page-break-inside: avoid; }
        .sign .label { font-weight: bold; border-bottom: 1pt solid #000; padding-bottom: 2pt; margin-bottom: 12pt; }
        .sign table { width: 100%; border-collapse: collapse; }
        .sign td { padding: 0; vertical-align: bottom; }
        .sign .des { font-weight: bold; text-decoration: underline; margin-top: 1pt; }
        .annex { page-break-before: always; }
        .annex .title { font-weight: bold; }
        .annex .heading { font-weight: bold; text-decoration: underline; margin-bottom: 4pt; }
        table.grid { width: 100%; border-collapse: collapse; margin: 2pt 0 12pt; font-size: 10.5pt; }
        table.grid th, table.grid td { border: 1pt solid #000; padding: 2pt 4pt; vertical-align: top; }
        table.grid th { text-align: left; font-weight: bold; }
        table.grid thead { display: table-header-group; }
        table.grid tr { page-break-inside: avoid; }
        table.grid .num { text-align: right; white-space: nowrap; }
        table.grid .mid { text-align: center; white-space: nowrap; }
        table.grid tr.total td { font-weight: bold; }
        table.people { width: 70%; }
        .note { font-size: 9.5pt; }
    </style>
</head>
<body>
    {{-- ── The memo ─────────────────────────────────────────────── --}}
    <table class="brand">
        <tr>
            <td class="logo">@if (is_file($logo))<img src="{{ $logo }}" alt="NG-CDF Board">@endif</td>
            <td class="org">NATIONAL GOVERNMENT CONSTITUENCIES<br>DEVELOPMENT FUND BOARD</td>
        </tr>
    </table>
    <div class="kind">INTERNAL MEMO</div>
    @if ($isDraft)<div class="draft">DRAFT: not yet submitted. Figures and team may still change.</div>@endif

    <table class="head">
        <tr><td class="k">TO:</td><td class="v">{{ $to }}</td></tr>
        <tr><td class="k">FROM:</td><td class="v">{{ $from }}</td></tr>
        <tr><td class="k">DATE:</td><td class="v">{{ $upper($date->format('jS F, Y')) }}</td></tr>
        <tr><td class="k">REF NO:</td><td class="v">{{ $refNo }}</td></tr>
        <tr><td class="k">SUBJECT:</td><td class="v">{{ $a->title }}</td></tr>
    </table>

    @foreach ($sections as $section)
        <h2>{{ $section['number'] }}&nbsp;&nbsp;&nbsp;{{ $section['heading'] }}</h2>
        @foreach ($section['paragraphs'] as $text)
            @if (trim($text) === '')
                <p><em>Not provided.</em></p>
            @else
                <p>{!! $para($text) !!}</p>
            @endif
        @endforeach
        @if ($section['bullets'] !== [])
            <ul class="bullets">@foreach ($section['bullets'] as $item)<li>{{ $item }}</li>@endforeach</ul>
        @endif
    @endforeach

    <h2>{{ count($sections) + 1 }}.0&nbsp;&nbsp;&nbsp;Request for approval</h2>
    <p>{!! $request !!}</p>
    <p>Therefore, this is to seek your authority for the above activity and the accompanying budget, to enable achievement of the intended activity.</p>

    @include('memos.partials.signatures', ['signatures' => $signatures])

    {{-- ── Annex: A, Activity Budget Request ────────────────────── --}}
    <div class="annex">
        <div class="title">Annex: A</div>
        <div class="heading">Activity Budget Request</div>
        <p>The following officers will be attending the activity, {{ $period->summary() }}, from {{ $a->start_date->format('jS F Y') }} to {{ $a->end_date->format('jS F Y') }}, at {{ $a->locationSummary() ?: 'the venue stated' }}:</p>

        <div class="title">BUDGET</div>
        <table class="grid">
            <thead>
                <tr><th>ITEM DESCRIPTION</th><th>CATEGORIES</th><th class="mid">SCALE</th><th class="mid">PAX</th><th class="mid">{{ $nightsBasis ? 'NIGHTS' : 'DAYS' }}</th><th class="num">RATE</th><th class="num">AMOUNT (KSHS)</th></tr>
            </thead>
            <tbody>
                @forelse ($detailed as $i => $r)
                    <tr>
                        @if ($spans[$i] > 0)<td rowspan="{{ $spans[$i] }}">{{ $upper($r['item']) }}</td>@endif
                        <td>{{ $r['category'] }}</td>
                        <td class="mid">{{ $r['scale'] }}</td>
                        <td class="mid">{{ $r['pax'] ?? '' }}</td>
                        <td class="mid">{{ $r['days'] ?? '' }}</td>
                        <td class="num">{{ $r['rate'] !== null ? $kes($r['rate']) : '' }}</td>
                        <td class="num">{{ $kes($r['amount']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No cost lines have been entered.</td></tr>
                @endforelse
                <tr class="total"><td colspan="6">Total</td><td class="num">{{ $kes($total) }}</td></tr>
            </tbody>
        </table>
        <p class="note">
            @if ($nightsBasis)
                DSA is paid per night: for an activity of two days or more, the night before the start (departure) and every night up to the end date; none for a same-day activity.
            @else
                DSA is paid per activity day.
            @endif
            Each officer's DSA follows their scale and their own days, at the rate in force on the start date. "(adjusted)" marks a DSA amount the Office of the CEO changed with a recorded reason.
        </p>

        @include('memos.partials.signatures', ['signatures' => $signatures])
    </div>

    {{-- ── Participants ──────────────────────────────────────────── --}}
    <div class="annex">
        <div class="title" style="margin-bottom: 8pt;">Participants</div>
        <table class="grid {{ $datesDiffer ? '' : 'people' }}">
            <thead><tr><th class="num">No.</th><th>Name</th><th>Designation</th>@if ($datesDiffer)<th>Dates</th>@endif</tr></thead>
            <tbody>
                @forelse ($itinerary as $i => $r)
                    <tr>
                        <td class="num">{{ $i + 1 }}.</td>
                        <td>{{ $r['name'] }}@if ($r['note'])<br><span class="note">{{ $r['note'] }}</span>@endif</td>
                        <td>{{ $r['designation'] }}</td>
                        @if ($datesDiffer)<td class="mid">{{ $r['from']->format('d M') }} to {{ $r['to']->format('d M Y') }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $datesDiffer ? 4 : 3 }}">No participants have been added.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
