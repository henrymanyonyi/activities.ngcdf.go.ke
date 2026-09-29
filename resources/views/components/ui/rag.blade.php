@props(['rag'])
@if ($rag)
    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold {{ str_replace('bg-', 'text-', explode(' ', $rag->badgeClasses())[1] ?? '') }}" title="{{ $rag->label() }}">
        <span class="w-2 h-2 rounded-full {{ $rag->dotClasses() }}"></span><span class="sr-only sm:not-sr-only">{{ $rag->label() }}</span>
    </span>
@endif
