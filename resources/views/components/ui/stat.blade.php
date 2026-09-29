@props(['icon', 'label', 'value', 'sub' => null, 'tone' => 'emerald', 'href' => null, 'index' => 0, 'money' => false])

@php
    // PAKA-RANGI §4.3 tier 3: hairlines computed per card.
    $edges = collect([
        $index % 2 === 1 ? 'border-l' : '',
        $index >= 2 ? 'border-t lg:border-t-0' : '',
        $index % 4 === 0 ? '' : 'lg:border-l',
        $index >= 4 ? 'lg:border-t' : '',
    ])->filter()->implode(' ');
    $tones = [
        'emerald' => 'bg-emerald-50 text-emerald-600',
        'amber' => 'bg-amber-50 text-amber-600',
        'blue' => 'bg-blue-50 text-blue-600',
        'red' => 'bg-red-50 text-red-600',
        'slate' => 'bg-slate-100 text-slate-500',
        'purple' => 'bg-purple-50 text-purple-600',
    ];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    class="py-4 px-4 border-gray-100 {{ $edges }} {{ $href ? 'hover:bg-slate-50/60 transition group' : '' }}">
    <div class="flex items-center gap-2 mb-1.5">
        <span class="w-7 h-7 rounded-lg flex items-center justify-center {{ $tones[$tone] ?? $tones['emerald'] }}">
            <i class="fa-solid {{ $icon }} text-xs" aria-hidden="true"></i>
        </span>
        <span class="{{ \App\Support\Ui::MICRO }}">{{ $label }}</span>
    </div>
    <p class="text-lg xl:text-xl font-bold text-slate-800 font-numeric leading-tight">
        @if ($money)<span class="text-[10px] font-semibold text-gray-400 mr-0.5 align-middle">KES</span>@endif{{ $value }}
    </p>
    @if ($sub)
        <p class="text-[11px] text-gray-400 mt-0.5">{{ $sub }}</p>
    @endif
</{{ $tag }}>
