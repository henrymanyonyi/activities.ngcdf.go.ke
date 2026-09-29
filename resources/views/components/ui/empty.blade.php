@props(['icon' => 'fa-inbox', 'title', 'line' => null])
{{-- PAKA-RANGI §8: centred on both axes. --}}
<div {{ $attributes->merge(['class' => 'flex-1 min-h-0 flex flex-col items-center justify-center text-center px-6 py-12']) }}>
    <div class="w-14 h-14 rounded-2xl bg-slate-50 flex items-center justify-center mb-3">
        <i class="fa-solid {{ $icon }} text-2xl text-slate-300" aria-hidden="true"></i>
    </div>
    <p class="text-base font-medium text-slate-500">{{ $title }}</p>
    @if ($line)<p class="text-sm text-slate-400 mt-1 max-w-md">{{ $line }}</p>@endif
    {{ $slot }}
</div>
