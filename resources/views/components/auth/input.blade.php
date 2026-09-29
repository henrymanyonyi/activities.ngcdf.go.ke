{{-- Auth field with a leading icon; `password` adds the show/hide toggle Smart uses. --}}
@props(['name', 'label', 'icon', 'type' => 'text', 'value' => null, 'password' => false])
<div @if ($password) x-data="{ show: false }" @endif>
    <label for="{{ $attributes->get('id', $name) }}" class="block text-sm font-semibold text-slate-700">{{ $label }}</label>
    <div class="mt-2 relative flex items-center">
        <i class="fa-solid {{ $icon }} absolute left-3.5 text-slate-400 text-sm pointer-events-none" aria-hidden="true"></i>
        <input id="{{ $attributes->get('id', $name) }}" name="{{ $name }}"
            @if ($password) :type="show ? 'text' : 'password'" type="password" @else type="{{ $type }}" @endif
            @if ($value !== null) value="{{ $value }}" @endif
            {{ $attributes->except('id')->merge(['class' => 'block w-full rounded-xl border-0 py-3.5 pl-10 '.($password ? 'pr-12' : 'pr-3').' text-slate-900 shadow-sm ring-1 ring-inset '.($errors->has($name) ? 'ring-red-300' : 'ring-gray-300').' placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-emerald-600 text-sm transition']) }}>
        @if ($password)
            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-emerald-600" :aria-label="show ? 'Hide password' : 'Show password'">
                <i class="fa-solid" :class="show ? 'fa-eye-slash' : 'fa-eye'"></i>
            </button>
        @endif
    </div>
</div>
