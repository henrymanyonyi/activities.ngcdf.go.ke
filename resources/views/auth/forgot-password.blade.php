<x-guest-layout>
    <x-auth.card title="Recover password">
        <div class="mb-6 text-sm text-slate-600 bg-emerald-50 p-4 rounded-lg border border-emerald-100 leading-relaxed">
            Enter your official email address and a secure link to reset your password will be sent to it.
        </div>

        <x-auth.errors class="mb-5" />

        <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
            @csrf
            <x-auth.input name="email" type="email" label="Official email" icon="fa-envelope" :value="old('email')" required autofocus autocomplete="username" />
            <button type="submit" class="w-full bg-emerald-700 text-white font-bold py-4 rounded-xl shadow-lg hover:bg-emerald-800 transition-all uppercase tracking-widest text-xs">
                <i class="fa-solid fa-paper-plane mr-1.5"></i>Send reset link
            </button>
            <div class="text-center">
                <a href="{{ route('login') }}" class="text-sm font-semibold text-slate-500 hover:text-emerald-700 transition"><i class="fa-solid fa-arrow-left mr-1"></i>Back to sign in</a>
            </div>
        </form>
    </x-auth.card>
</x-guest-layout>
