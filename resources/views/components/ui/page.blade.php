@props([
    'icon' => 'fa-circle',
    'title',
    'subtitle' => null,
    'statsKey' => null,
    // 'body': the body scrolls as one region (detail pages, dashboards).
    // 'inner': the slot manages its own scroller (list pages, PAKA-RANGI §4.4).
    'scroll' => 'body',
])

{{-- PAKA-RANGI §4.2 wrapper: fixed header, only the body scrolls. --}}
<div {{ $attributes->merge(['class' => '-m-8 h-[calc(100vh-4rem)] flex flex-col bg-gray-50 overflow-hidden']) }}
    @if ($statsKey)
        x-data="{ statsOpen: (() => { try { return localStorage.getItem(@js($statsKey)) !== 'false' } catch (e) { return true } })() }"
        x-init="$watch('statsOpen', v => { try { localStorage.setItem(@js($statsKey), v) } catch (e) {} })"
    @endif>

    <div class="flex-shrink-0 bg-white border-b border-gray-200">
        <div class="px-8 pt-5 pb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3.5 min-w-0">
                <div class="w-11 h-11 rounded-xl bg-emerald-600 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid {{ $icon }} text-white" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h1 class="text-xl font-bold text-slate-800 leading-tight">{{ $title }}</h1>
                        {{ $pills ?? '' }}
                    </div>
                    @if ($subtitle)
                        <p class="text-[13px] text-gray-500 mt-0.5">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto flex-wrap">
                @if ($statsKey && isset($stats))
                    <button type="button" @click="statsOpen = !statsOpen" class="{{ \App\Support\Ui::BTN_NEUTRAL }}" :aria-expanded="statsOpen">
                        <i class="fa-solid fa-chart-simple text-xs"></i><span x-text="statsOpen ? 'Hide figures' : 'Show figures'"></span>
                    </button>
                @endif
                {{ $actions ?? '' }}
            </div>
        </div>

        @isset($tabs)
            <div class="px-8 pb-4">{{ $tabs }}</div>
        @endisset

        @isset($filters)
            {{-- PAKA-RANGI §4.3: the row fills edge to edge; only the search grows. --}}
            <div class="px-8 pb-4 flex flex-col lg:flex-row lg:items-center lg:flex-wrap gap-3">{{ $filters }}</div>
        @endisset

        @isset($stats)
            <div @if ($statsKey) x-show="statsOpen" x-collapse @endif class="px-8 border-t border-gray-100 grid grid-cols-2 lg:grid-cols-4">{{ $stats }}</div>
        @endisset
    </div>

    @if ($scroll === 'inner')
        <div class="flex-1 min-h-0 px-8 py-6 flex flex-col gap-4 overflow-hidden">{{ $slot }}</div>
    @else
        <div class="flex-1 min-h-0 overflow-y-auto">
            <div class="px-8 py-6 flex flex-col gap-4">{{ $slot }}</div>
        </div>
    @endif
</div>
