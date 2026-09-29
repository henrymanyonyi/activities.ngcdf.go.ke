<x-guest-layout>
    <x-auth.card title="Confirm your password" icon="fa-lock">
        <p class="-mt-5 mb-6 text-center text-slate-500 text-sm">This is a secure area. Confirm your password to continue.</p>

        <x-auth.errors class="mb-5" />

        <form method="POST" action="{{ route('password.confirm') }}" class="space-y-6">
            @csrf
            <x-auth.input name="password" label="Password" icon="fa-lock" password required autofocus autocomplete="current-password" />
            <button type="submit" class="w-full bg-emerald-700 text-white font-bold py-4 rounded-xl shadow-lg hover:bg-emerald-800 transition-all uppercase tracking-widest text-xs">
                <i class="fa-solid fa-check mr-1.5"></i>Confirm
            </button>
        </form>
    </x-auth.card>
</x-guest-layout>
