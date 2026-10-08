@extends('privacy.layout')

@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 text-[13px] font-semibold text-white bg-emerald-600 hover:bg-emerald-700 px-4 py-2.5 rounded-lg transition whitespace-nowrap disabled:opacity-60';
    $btnNeutral = 'inline-flex items-center justify-center gap-2 text-[13px] font-medium text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 px-4 py-2.5 rounded-lg transition whitespace-nowrap';
@endphp

@section('content')
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-4xl mx-auto px-4 sm:px-8 py-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <a href="{{ url('/') }}" class="flex items-center gap-3.5 min-w-0">
                <img src="{{ asset(config('privacy.logo')) }}" alt="NG-CDF" class="h-10 w-auto">
                <div class="min-w-0">
                    <h1 class="text-xl font-bold text-slate-800 leading-tight">Data Privacy Statement</h1>
                    <p class="text-[13px] text-gray-500 mt-0.5">
                        {{ config('privacy.statement.title') }} &middot;
                        <span class="font-semibold text-slate-700">Version {{ $version }}</span>
                    </p>
                </div>
            </a>
            <a href="{{ route('privacy.statement.download') }}" class="{{ $btnNeutral }}">
                <i class="fa-solid fa-file-pdf text-xs"></i> Download PDF
            </a>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 sm:px-8 py-8 space-y-6">
        @if (session('privacy_status'))
            <div class="flex items-start gap-2 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-4 py-3 text-sm">
                <i class="fa-solid fa-circle-check mt-0.5"></i>
                <span>{{ session('privacy_status') }}</span>
            </div>
        @endif

        @if ($returnTo && ! $consented)
            <div class="flex items-start gap-2 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm">
                <i class="fa-solid fa-shield-halved mt-0.5"></i>
                <span>Before you submit your personal information, please read the Data Privacy Statement below and confirm that you agree to it.</span>
            </div>
        @endif

        <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 sm:p-8">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-4">
                Applicable to all NG-CDF Board publications, websites, digital platforms and applications
            </p>
            @include('privacy.partials.statement')
        </section>

        <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            @if ($consented)
                <div class="px-5 sm:px-6 py-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-600 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-check text-white"></i>
                        </div>
                        <div>
                            <p class="text-base font-bold text-slate-800 leading-tight">You have agreed to version {{ $version }}</p>
                            <p class="text-xs text-slate-500 mt-0.5">Your consent to this version of the Data Privacy Statement is on record.</p>
                        </div>
                    </div>
                    <a href="{{ $returnTo ?? url('/') }}" class="{{ $btnPrimary }}">
                        <i class="fa-solid fa-arrow-right text-xs"></i> Continue
                    </a>
                </div>
            @else
                <form method="POST" action="{{ route('privacy.statement.consent') }}">
                    @csrf
                    <div class="px-5 sm:px-6 py-5 space-y-3">
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 cursor-pointer">
                            <input type="checkbox" name="agree" value="1" required
                                class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm text-slate-700">
                                I have read and understood the NG-CDF Board Data Privacy Statement (version {{ $version }}) and agree to its terms.
                            </span>
                        </label>
                        @error('agree') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <p class="text-xs text-slate-400">
                            Your consent is recorded against this version of the statement, with the date and time. No name, ID number,
                            email, phone number or other personal details are asked for or stored with it. This browser remembers your
                            consent so you are not asked again until the statement changes.
                        </p>
                    </div>
                    <div class="px-5 sm:px-6 py-4 border-t border-slate-100 bg-slate-50 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3">
                        <a href="{{ route('privacy.statement.download') }}" class="{{ $btnNeutral }}">
                            <i class="fa-solid fa-file-pdf text-xs"></i> Download PDF
                        </a>
                        <button type="submit" class="{{ $btnPrimary }}">
                            <i class="fa-solid fa-check text-xs"></i> Agree &amp; Continue
                        </button>
                    </div>
                </form>
            @endif
        </section>

        <p class="text-center text-xs text-slate-400">
            Questions about this statement? Write to the Data Protection Officer at
            <a href="mailto:dpo@ngcdf.go.ke" class="text-emerald-700 font-medium hover:underline">dpo@ngcdf.go.ke</a>.
        </p>
    </main>
@endsection
