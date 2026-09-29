{{-- PAKA-RANGI §8 failure flash, replacing Jetstream's "Whoops!" list. --}}
@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'px-4 py-3 rounded-lg text-sm bg-red-50 border border-red-200 text-red-700']) }} role="alert">
        @if ($errors->count() === 1)
            <p><i class="fa-solid fa-circle-exclamation mr-1.5"></i>{{ $errors->first() }}</p>
        @else
            <ul class="space-y-1">@foreach ($errors->all() as $error)<li><i class="fa-solid fa-circle-exclamation mr-1.5"></i>{{ $error }}</li>@endforeach</ul>
        @endif
    </div>
@endif
@if (session('status'))
    <div class="px-4 py-3 rounded-lg text-sm bg-emerald-50 border border-emerald-200 text-emerald-700 {{ $errors->any() ? 'mt-3' : '' }}" role="status">
        <i class="fa-solid fa-circle-check mr-1.5"></i>{{ session('status') }}
    </div>
@endif
