@props(['label', 'for' => null, 'error' => null, 'hint' => null, 'required' => false])
<div {{ $attributes }}>
    <label @if ($for) for="{{ $for }}" @endif class="{{ \App\Support\Ui::LABEL }}">{{ $label }}@if ($required)<span class="text-red-500" aria-hidden="true"> *</span>@endif</label>
    {{ $slot }}
    @if ($hint)<p class="text-[11px] text-slate-400 mt-1">{{ $hint }}</p>@endif
    @if ($error)
        @error($error)<p class="text-xs text-red-600 mt-1" role="alert">{{ $message }}</p>@enderror
    @endif
</div>
