{{-- PDF and print layout for a standard report. RESTRICTED at top and bottom of every page (FRD CF-07). --}}
@php
    $columns = $report->columns();
    $totals = $report->totals($rows);
    $stamp = 'Produced by '.$user->name.' ('.$user->roleLabel().') on '.now()->format('d M Y H:i');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title() }} · RESTRICTED</title>
    <style>
        @page { margin: 70px 28px 60px 28px; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 9px; color: #1e293b; }
        header.mark, footer.mark { position: fixed; left: 0; right: 0; text-align: center; font-weight: bold; color: #b91c1c; letter-spacing: 2px; font-size: 9px; }
        header.mark { top: -52px; }
        footer.mark { bottom: -42px; }
        footer.mark .stamp { display: block; color: #64748b; letter-spacing: 0; font-weight: normal; margin-top: 3px; }
        h1 { font-size: 15px; margin: 0 0 2px; color: #065f46; }
        .sub { color: #64748b; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #ecfdf5; color: #065f46; text-align: left; padding: 5px 4px; font-size: 8px; text-transform: uppercase; border-bottom: 1px solid #a7f3d0; }
        td { padding: 4px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: bold; border-top: 2px solid #065f46; }
        .brand { display: flex; align-items: center; gap: 8px; }
        @media print { .no-print { display: none; } body { font-size: 10px; } }
        @media screen { body { max-width: 1100px; margin: 20px auto; } header.mark, footer.mark { position: static; margin: 8px 0; } }
    </style>
</head>
<body @if ($print ?? false) onload="window.print()" @endif>
    <header class="mark">RESTRICTED: FOR THE OFFICE OF THE CEO ONLY</header>
    <footer class="mark">RESTRICTED: FOR THE OFFICE OF THE CEO ONLY<span class="stamp">{{ $stamp }}</span></footer>

    <div class="sub">NG-CDF Board · Office of the CEO / Accounting Officer · Field Activity Planning and Monitoring</div>
    <h1>{{ $report->title() }}</h1>
    <div class="sub">{{ $report->subtitle($filters) }} · {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('row', $rows->count()) }}</div>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $key => [$label, $type])
                    <th class="{{ in_array($type, ['money', 'int', 'percent']) ? 'num' : '' }}">{{ $label }}{{ $type === 'money' ? ' (KES)' : '' }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $key => [$label, $type])
                        <td class="{{ in_array($type, ['money', 'int', 'percent']) ? 'num' : '' }}">{{ $report->format($row[$key] ?? null, $type) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}">No records for the selected filters.</td></tr>
            @endforelse
            @if ($totals && $rows->isNotEmpty())
                <tr class="total">
                    @foreach (array_keys($columns) as $i => $key)
                        <td class="num">{{ $i === 0 ? 'Total' : (isset($totals[$key]) ? \App\Support\Money::format($totals[$key]) : '') }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
