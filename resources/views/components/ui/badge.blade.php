@props(['classes' => 'bg-slate-100 text-slate-500', 'icon' => null])
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap {$classes}"]) }}>
    @if ($icon)<i class="fa-solid {{ $icon }} text-[9px]" aria-hidden="true"></i>@endif{{ $slot }}
</span>
