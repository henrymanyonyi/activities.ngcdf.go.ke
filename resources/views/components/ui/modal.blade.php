{{--
    PAKA-RANGI §5: one chrome, three sizes. `show` is the Livewire boolean
    property that opens it (pass its value as :open); the backdrop is its own div so clicking outside closes.
--}}
@props(['show', 'open' => false, 'size' => 'medium', 'icon' => 'fa-pen', 'title', 'subtitle' => null, 'tone' => 'emerald'])
@php
    $width = ['large' => 'max-w-7xl max-h-[90vh]', 'medium' => 'max-w-2xl max-h-[90vh]', 'slim' => 'max-w-lg max-h-[90vh]', 'alert' => 'max-w-md'][$size];
    $tile = $tone === 'red' ? 'bg-red-600' : 'bg-emerald-600';
@endphp
@if ($open)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="modal-{{ $show }}"
        x-data x-on:keydown.escape.window="$wire.set('{{ $show }}', false)" role="dialog" aria-modal="true" aria-labelledby="modal-{{ $show }}-title">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" wire:click="$set('{{ $show }}', false)"></div>
        <div class="relative z-10 w-full {{ $width }} bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden">
            <header class="shrink-0 flex items-start gap-3 px-6 py-4 border-b border-slate-100">
                <div class="w-10 h-10 rounded-xl {{ $tile }} flex items-center justify-center shrink-0">
                    <i class="fa-solid {{ $icon }} text-white text-sm" aria-hidden="true"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 id="modal-{{ $show }}-title" class="text-base font-bold text-slate-800 leading-tight">{{ $title }}</h2>
                    @if ($subtitle)<p class="text-xs text-slate-500 mt-0.5">{{ $subtitle }}</p>@endif
                </div>
                <button type="button" wire:click="$set('{{ $show }}', false)" class="{{ \App\Support\Ui::BTN_ICON }}" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </header>
            <div class="{{ $size === 'alert' ? '' : 'flex-1 overflow-y-auto' }} p-6 space-y-4">{{ $slot }}</div>
            @isset($footer)
                <footer class="shrink-0 px-6 py-4 border-t border-slate-100 bg-slate-50 flex flex-wrap items-center justify-end gap-2">{{ $footer }}</footer>
            @endisset
        </div>
    </div>
@endif
