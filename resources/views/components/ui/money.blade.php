{{-- Money: muted KES prefix, two decimals, tabular (PAKA-RANGI §2.5). Accepts cents (int) or a decimal string. --}}
@props(['value', 'muted' => false])
@php
    $cents = is_int($value) ? $value : \App\Support\Money::toCents($value);
@endphp
<span {{ $attributes->merge(['class' => 'font-numeric whitespace-nowrap '.($muted ? 'text-slate-400' : '')]) }}><span class="text-[10px] font-semibold text-gray-400 mr-0.5">KES</span>{{ \App\Support\Money::format($cents) }}</span>
