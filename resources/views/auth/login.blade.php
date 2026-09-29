{{-- Sign in, laid out as Smart NG-CDF's login (split screen, branded panel left, form right). --}}
<x-guest-layout>
    <div class="flex min-h-screen bg-white">
        {{-- Branding panel. A gradient rather than Smart's hotlinked photo: this page must not call third-party image hosts. --}}
        <div class="relative hidden w-0 flex-1 lg:block">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-950 via-emerald-900 to-emerald-700"></div>
            <div class="absolute inset-0 opacity-[0.07]" style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 22px 22px;"></div>
            <div class="relative h-full flex flex-col justify-between p-12">
                <div class="flex items-center gap-3">
                    <div class="bg-white p-2 rounded-xl shadow-lg"><img src="{{ asset('images/ngcdf-logo.png') }}" alt="NG-CDF" class="h-12 w-auto"></div>
                    <div>
                        <span class="block text-white font-bold text-xl tracking-tight uppercase">NG-CDF FAPM</span>
                        <span class="block text-emerald-300 text-xs">Office of the CEO / Accounting Officer</span>
                    </div>
                </div>

                <div class="text-white max-w-lg">
                    <h2 class="text-5xl font-bold leading-tight mb-6">
                        Field activities, <br><span class="text-amber-400">planned,</span> <br>decided and delivered.
                    </h2>
                    <p class="text-emerald-50 text-lg leading-relaxed">
                        The Field Activity Planning and Monitoring System gives the CEO one confidential view of every planned,
                        ongoing and completed field activity, its team and its cost.
                    </p>
                    <div class="mt-8 inline-flex items-start gap-3 rounded-xl bg-white/10 border border-white/15 px-4 py-3 text-sm text-emerald-50">
                        <i class="fa-solid fa-lock mt-0.5 text-amber-400" aria-hidden="true"></i>
                        <span><strong class="font-semibold text-white">Restricted.</strong> For the CEO, the Chief of Staff and the Assistant Chief of Staff only. Every sign-in is recorded.</span>
                    </div>
                </div>

                <div class="flex gap-6 text-emerald-200 text-sm">
                    <span>&copy; {{ date('Y') }} NG-CDF Board · Republic of Kenya</span>
                </div>
            </div>
        </div>

        {{-- Form --}}
        <div class="flex flex-1 flex-col justify-center px-6 py-12 lg:flex-none lg:px-24 xl:px-32">
            <div class="mx-auto w-full max-w-sm lg:w-96">
                <div class="lg:hidden flex flex-col items-center gap-3 mb-8">
                    <img src="{{ asset('images/ngcdf-logo.png') }}" alt="NG-CDF" class="h-16 w-auto">
                    <span class="font-bold text-emerald-900 uppercase tracking-tight">NG-CDF FAPM</span>
                </div>

                <x-ui.restricted />
                <h1 class="mt-4 text-3xl font-bold tracking-tight text-slate-900">Sign in</h1>
                <p class="mt-2 text-sm text-slate-500">Office of the CEO: authorised officers only.</p>

                <div class="mt-8">
                    <x-auth.errors class="mb-5" />

                    <form method="POST" action="{{ route('login') }}" class="space-y-6">
                        @csrf
                        <x-auth.input name="email" type="email" label="Email address" icon="fa-envelope" :value="old('email')" required autofocus autocomplete="username" placeholder="name@ngcdf.go.ke" />
                        <x-auth.input name="password" label="Password" icon="fa-lock" password required autocomplete="current-password" placeholder="••••••••" />

                        <div class="flex items-center justify-between">
                            <label for="remember_me" class="flex items-center gap-3 text-sm text-slate-600 cursor-pointer">
                                <input id="remember_me" name="remember" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-600">
                                Keep me signed in
                            </label>
                            @if (Route::has('password.request'))
                                <a href="{{ route('password.request') }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-500 transition">Forgot password?</a>
                            @endif
                        </div>

                        <button type="submit" class="flex w-full justify-center items-center gap-2 rounded-xl bg-emerald-700 px-3 py-4 text-sm font-bold uppercase tracking-wide text-white shadow-lg hover:bg-emerald-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 transition-all active:scale-[0.98]">
                            <i class="fa-solid fa-right-to-bracket text-xs"></i>Sign in
                        </button>
                    </form>

                    <p class="mt-6 text-xs text-slate-400 leading-relaxed">
                        Two-factor authentication is required. After several failed attempts the account is locked for a while; the Chief of Staff can unlock it.
                    </p>

                    <div class="mt-8 border-t border-slate-100 pt-6 flex items-center justify-center gap-3 opacity-60 grayscale hover:grayscale-0 transition duration-500">
                        <img src="{{ asset('images/gok.png') }}" alt="Government of Kenya" class="h-5 w-auto">
                        <span class="text-slate-300">|</span>
                        <img src="{{ asset('images/ngcdf-logo.png') }}" alt="NG-CDF" class="h-5 w-auto">
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
