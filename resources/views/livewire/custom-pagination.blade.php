{{-- Copied from Smart NG-CDF (PAKA-RANGI §8), with its two invalid colour classes corrected. --}}
<div class="flex items-center justify-between flex-wrap gap-3">
    <div class="text-sm text-gray-500 font-numeric">
        @if ($paginator->total() > 0)
            Showing <span class="font-medium text-gray-700">{{ $paginator->firstItem() ?? 1 }}</span> to
            <span class="font-medium text-gray-700">{{ $paginator->lastItem() ?? $paginator->count() }}</span> of
            <span class="font-medium text-gray-700">{{ $paginator->total() }}</span> results
        @else
            No results
        @endif
    </div>

    <nav class="inline-flex items-center bg-emerald-50 border border-emerald-200 rounded-lg overflow-hidden divide-x divide-emerald-200/50" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="px-3 py-2 text-xs font-semibold text-emerald-300 cursor-not-allowed flex items-center justify-center min-w-8"><i class="fa-solid fa-chevron-left text-[10px]"></i></span>
        @else
            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" aria-label="Previous page" class="px-3 py-2 text-xs font-semibold text-emerald-700 hover:text-emerald-900 hover:bg-emerald-100 flex items-center justify-center min-w-8 transition"><i class="fa-solid fa-chevron-left text-[10px]"></i></button>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="px-3 py-2 text-xs font-semibold text-emerald-300 min-w-8 text-center">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page" class="px-3 py-2 text-xs font-bold text-white bg-emerald-600 min-w-8 text-center font-numeric">{{ $page }}</span>
                    @else
                        <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="px-3 py-2 text-xs font-semibold text-emerald-700 hover:text-emerald-900 hover:bg-emerald-100 min-w-8 transition font-numeric">{{ $page }}</button>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" aria-label="Next page" class="px-3 py-2 text-xs font-semibold text-emerald-700 hover:text-emerald-900 hover:bg-emerald-100 flex items-center justify-center min-w-8 transition"><i class="fa-solid fa-chevron-right text-[10px]"></i></button>
        @else
            <span class="px-3 py-2 text-xs font-semibold text-emerald-300 cursor-not-allowed flex items-center justify-center min-w-8"><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
        @endif
    </nav>
</div>
