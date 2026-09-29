@use('App\Support\Ui')
<x-ui.page icon="fa-users-gear" title="User accounts" subtitle="Exactly three accounts: CEO, Chief of Staff and Assistant Chief of Staff (FRD CF-01). No other role or account can exist.">
    @if ($oneTimePassword)
        <div class="px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm" role="alert">
            <p class="font-semibold"><i class="fa-solid fa-key mr-1"></i>One-time password for {{ $oneTimeFor }}</p>
            <p class="font-numeric text-base mt-1 select-all">{{ $oneTimePassword }}</p>
            <p class="text-[12px] mt-1">Shown once. Share it privately. Two-factor authentication must be set up at first sign-in.</p>
            <button type="button" wire:click="$set('oneTimePassword', null)" class="mt-2 {{ Ui::BTN_TINT }}">I have shared it</button>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        @foreach ($slots as $role => $slot)
            <x-ui.card :title="$slot['label']" icon="fa-user-shield" padded>
                @if ($u = $slot['active'])
                    <p class="text-sm font-semibold text-slate-800">{{ $u->name }}</p>
                    <p class="text-[13px] text-slate-500">{{ $u->email }}</p>
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <x-ui.badge :classes="$u->two_factor_confirmed_at ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'" icon="fa-shield-halved">{{ $u->two_factor_confirmed_at ? 'Two-factor on' : 'Two-factor not set up' }}</x-ui.badge>
                        @if ($u->isLocked())<x-ui.badge classes="bg-red-50 text-red-700" icon="fa-lock">Locked until {{ $u->locked_until->format('H:i') }}</x-ui.badge>@endif
                    </div>
                    <p class="text-[11px] text-slate-400 mt-2 font-numeric">Last sign-in {{ $u->last_login_at?->format('d M Y H:i') ?? 'never' }}</p>
                    <div class="flex flex-wrap gap-2 mt-4">
                        @if ($u->isLocked())<button type="button" wire:click="unlock({{ $u->id }})" class="{{ Ui::BTN_TINT }}">Unlock</button>@endif
                        <button type="button" wire:click="confirmReset({{ $u->id }})" class="{{ Ui::BTN_TINT }}">Reset password</button>
                        <button type="button" wire:click="resetTwoFactor({{ $u->id }})" class="{{ Ui::BTN_TINT }}">Reset two-factor</button>
                        @unless ($u->is(auth()->user()))
                            <button type="button" wire:click="deactivate({{ $u->id }})" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-red-700 bg-red-50 hover:bg-red-100 border border-red-200 px-2.5 py-1.5 rounded-lg">Deactivate</button>
                        @endunless
                    </div>
                @else
                    <x-ui.empty icon="fa-user-plus" title="No active account" class="!py-6">
                        <button type="button" wire:click="openCreate('{{ $role }}')" class="mt-3 {{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Create account</button>
                    </x-ui.empty>
                @endif

                @if ($slot['former']->isNotEmpty())
                    <div class="mt-4 pt-3 border-t border-slate-100">
                        <p class="{{ Ui::MICRO }} mb-1">Former holders</p>
                        @foreach ($slot['former'] as $f)
                            <div class="flex items-center justify-between text-[12px] text-slate-500 py-1">
                                <span>{{ $f->name }} · {{ $f->email }}</span>
                                @unless ($slot['active'])<button type="button" wire:click="reactivate({{ $f->id }})" class="text-emerald-700 font-semibold hover:underline">Reactivate</button>@endunless
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.modal show="showReset" :open="$showReset" size="alert" icon="fa-key" title="Issue a new one-time password?" subtitle="The user is signed out and must sign in with the new password.">
        <x-slot:footer>
            <button type="button" wire:click="$set('showReset', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="resetPassword" wire:loading.attr="disabled" wire:target="resetPassword" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-key text-xs"></i>Reset password</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showCreate" :open="$showCreate" size="slim" icon="fa-user-plus" :title="'Create '.(\App\Models\User::ROLES[$role] ?? '').' account'" subtitle="A one-time password is shown once after saving.">
        @error('role')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <x-ui.field label="Full name" for="u-n" error="name" required><input id="u-n" type="text" wire:model="name" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Email" for="u-e" error="email" required><input id="u-e" type="email" wire:model="email" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showCreate', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="create" wire:loading.attr="disabled" wire:target="create" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-user-plus text-xs"></i>Create</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
