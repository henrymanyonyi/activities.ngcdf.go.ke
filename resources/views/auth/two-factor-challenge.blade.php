<x-guest-layout>
    <x-auth.card title="Security check" icon="fa-shield-halved" dark>
        <div x-data="{ recovery: false }">
            <p class="-mt-5 mb-6 text-center text-slate-500 text-sm" x-show="! recovery">Enter the 6-digit code from your authenticator app.</p>
            <p class="-mt-5 mb-6 text-center text-slate-500 text-sm" x-cloak x-show="recovery">Enter one of your emergency recovery codes.</p>

            <x-auth.errors class="mb-5" />

            <form method="POST" action="{{ route('two-factor.login') }}">
                @csrf
                <div x-show="! recovery">
                    <label for="code" class="block text-xs font-bold uppercase text-emerald-800">Authentication code</label>
                    <input id="code" type="text" inputmode="numeric" name="code" autofocus x-ref="code" autocomplete="one-time-code" placeholder="000000" maxlength="6"
                        class="block mt-1 w-full text-center text-2xl tracking-[0.75rem] font-numeric py-4 border-gray-200 bg-gray-50 rounded-xl focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <div x-cloak x-show="recovery">
                    <label for="recovery_code" class="block text-xs font-bold uppercase text-emerald-800">Recovery code</label>
                    <input id="recovery_code" type="text" name="recovery_code" x-ref="recovery_code" autocomplete="one-time-code"
                        class="block mt-1 w-full py-4 px-4 bg-gray-50 border-gray-200 rounded-xl focus:border-emerald-600 focus:ring-emerald-600 font-numeric">
                </div>

                <div class="mt-8 space-y-4">
                    <button type="submit" class="w-full bg-emerald-700 text-white font-bold py-4 rounded-xl shadow-lg hover:bg-emerald-800 transition-all uppercase tracking-widest text-xs">
                        <i class="fa-solid fa-user-check mr-1.5"></i>Verify identity
                    </button>
                    <div class="flex justify-center">
                        <button type="button" class="text-xs font-bold text-emerald-700 hover:underline" x-show="! recovery" x-on:click="recovery = true; $nextTick(() => $refs.recovery_code.focus())">Use a recovery code</button>
                        <button type="button" class="text-xs font-bold text-emerald-700 hover:underline" x-cloak x-show="recovery" x-on:click="recovery = false; $nextTick(() => $refs.code.focus())">Use an authentication code</button>
                    </div>
                </div>
            </form>
        </div>
    </x-auth.card>
</x-guest-layout>
