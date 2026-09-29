@props(['title' => null, 'count' => null, 'icon' => null, 'padded' => false])
<section {{ $attributes->merge(['class' => \App\Support\Ui::CARD.' flex flex-col']) }}>
    @if ($title || isset($actions))
        <div class="{{ \App\Support\Ui::CAPTION }}">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 flex items-center gap-2">
                @if ($icon)<i class="fa-solid {{ $icon }} text-[10px]" aria-hidden="true"></i>@endif{{ $title }}
                @if ($count !== null)<span class="font-numeric text-slate-500 normal-case tracking-normal">{{ $count }}</span>@endif
            </h2>
            <div class="flex items-center gap-2">{{ $actions ?? '' }}</div>
        </div>
    @endif
    <div class="{{ $padded ? 'p-5' : '' }} flex-1 min-h-0">{{ $slot }}</div>
    @isset($footer)
        <div class="shrink-0 px-5 py-3 bg-slate-50 border-t border-slate-200">{{ $footer }}</div>
    @endisset
</section>
