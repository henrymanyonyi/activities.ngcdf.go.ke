{{-- Centred auth card, as Smart's recover / reset / two-factor pages. `dark` puts it on the emerald background (security check). --}}
@props(['title', 'subtitle' => null, 'icon' => null, 'dark' => false])
<div class="min-h-screen flex flex-col justify-center items-center px-4 sm:px-6 py-10 {{ $dark ? 'bg-emerald-900' : 'bg-slate-50' }}">
    <x-ui.restricted class="mb-5" />
    <div class="w-full max-w-md bg-white p-8 sm:p-10 rounded-2xl {{ $dark ? 'shadow-2xl' : 'shadow-xl border-t-8 border-emerald-700' }}">
        <div class="text-center mb-8">
            @if ($icon)
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-amber-100 mb-4"><i class="fa-solid {{ $icon }} text-amber-600 text-2xl" aria-hidden="true"></i></div>
            @else
                <img src="{{ asset('images/ngcdf-logo.png') }}" alt="NG-CDF" class="h-16 w-auto mx-auto mb-4">
            @endif
            <h1 class="text-2xl font-bold text-slate-900 {{ $icon ? '' : 'uppercase' }}">{{ $title }}</h1>
            @if ($subtitle)<p class="text-slate-500 text-sm mt-2">{{ $subtitle }}</p>@endif
        </div>
        {{ $slot }}
    </div>
    <p class="mt-6 text-[11px] {{ $dark ? 'text-emerald-300' : 'text-slate-400' }}">NG-CDF Board · Office of the CEO · Field Activity Planning &amp; Monitoring</p>
</div>
