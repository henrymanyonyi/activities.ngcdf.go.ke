@extends('privacy.layout')

@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 text-[13px] font-semibold text-white bg-emerald-600 hover:bg-emerald-700 px-4 py-2.5 rounded-lg transition whitespace-nowrap disabled:opacity-60';
    $btnNeutral = 'inline-flex items-center justify-center gap-2 text-[13px] font-medium text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 px-4 py-2.5 rounded-lg transition whitespace-nowrap';
@endphp

{{--
    The mandatory consent popup (EnsurePrivacyConsent). Medium popup chrome
    (PAKA-RANGI §5.3) over a blank page, deliberately without the click-
    outside backdrop, Escape or a close button: the only ways on are
    agreeing or signing out.
--}}
@section('content')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>

        <form method="POST" action="{{ route($acceptRoute) }}"
            role="dialog" aria-modal="true" aria-labelledby="privacy-consent-title"
            class="relative z-10 w-full max-w-2xl max-h-[90vh] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden">
            @csrf

            <header class="shrink-0 px-6 py-4 border-b border-slate-100 flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-600 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-shield-halved text-white"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 id="privacy-consent-title" class="text-base font-bold text-slate-800 leading-tight">{{ config('privacy.statement.title') }}</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Version {{ $version }} &middot; Please read and agree to this statement before you continue to {{ config('privacy.app_name') }}.
                    </p>
                </div>
                <a href="{{ route('privacy.statement.download') }}" class="{{ $btnNeutral }} shrink-0" title="Download PDF">
                    <i class="fa-solid fa-file-pdf text-xs"></i><span class="hidden sm:inline">PDF</span>
                </a>
            </header>

            <div class="flex-1 overflow-y-auto px-6 py-5">
                @include('privacy.partials.statement')
            </div>

            <footer class="shrink-0 px-6 py-4 border-t border-slate-100 bg-slate-50 space-y-3">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="agree" value="1" required
                        class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="text-sm text-slate-700">
                        I have read and understood the NG-CDF Board Data Privacy Statement (version {{ $version }}) and agree to its terms.
                    </span>
                </label>
                @error('agree') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3">
                    @if ($logoutRoute)
                        <button type="submit" form="privacy-consent-logout" formnovalidate class="{{ $btnNeutral }}">
                            <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i> Sign out
                        </button>
                    @endif
                    <button type="submit" class="{{ $btnPrimary }}">
                        <i class="fa-solid fa-check text-xs"></i> Agree &amp; Continue
                    </button>
                </div>
            </footer>
        </form>

        @if ($logoutRoute)
            <form id="privacy-consent-logout" action="{{ route($logoutRoute) }}" method="POST" class="hidden">
                @csrf
            </form>
        @endif
    </div>
@endsection
