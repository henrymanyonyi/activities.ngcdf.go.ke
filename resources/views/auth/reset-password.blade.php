<x-guest-layout>
    <x-auth.card title="Reset password">
        <div class="mb-6 text-sm text-slate-600 bg-emerald-50 p-4 rounded-lg border border-emerald-100 leading-relaxed">
            Choose a new password for your account.
        </div>

        <x-auth.errors class="mb-5" />

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <x-auth.input name="email" type="email" label="Official email" icon="fa-envelope" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-auth.input name="password" label="New password" icon="fa-lock" password required autocomplete="new-password" />
            <x-auth.input name="password_confirmation" label="Confirm new password" icon="fa-lock" password required autocomplete="new-password" />
            <button type="submit" class="w-full bg-emerald-700 text-white font-bold py-4 rounded-xl shadow-lg hover:bg-emerald-800 transition-all uppercase tracking-widest text-xs">
                <i class="fa-solid fa-key mr-1.5"></i>Reset password
            </button>
            <div class="text-center">
                <a href="{{ route('login') }}" class="text-sm font-semibold text-slate-500 hover:text-emerald-700 transition"><i class="fa-solid fa-arrow-left mr-1"></i>Back to sign in</a>
            </div>
        </form>
    </x-auth.card>
</x-guest-layout>
