<?php

namespace App\Livewire;

use App\Livewire\Concerns\RunsActions;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * FRD CF-01 / CF-04: exactly three accounts, one active holder per role,
 * managed by the Chief of Staff. Accounts are deactivated, never deleted.
 */
#[Layout('layouts.admin')]
#[Title('User accounts')]
class Users extends Component
{
    use RunsActions;

    public bool $showCreate = false;

    public string $role = '';

    public string $name = '';

    public string $email = '';

    /** Shown once after creating or resetting, never stored in plain text. */
    public ?string $oneTimePassword = null;

    public ?string $oneTimeFor = null;

    public bool $showReset = false;

    public ?int $resetId = null;

    private function guard(): void
    {
        abort_unless(Auth::user()->can('users.manage'), 403);
    }

    public function openCreate(string $role): void
    {
        abort_unless(array_key_exists($role, User::ROLES), 404);
        $this->role = $role;
        $this->reset(['name', 'email']);
        $this->resetErrorBag();
        $this->showCreate = true;
    }

    public function create(): void
    {
        $this->guard();
        $this->validate([
            'role' => ['required', 'in:'.implode(',', array_keys(User::ROLES))],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        if (User::query()->role($this->role)->where('is_active', true)->exists()) {
            $this->addError('role', 'There is already an active '.User::ROLES[$this->role].' account. Deactivate it first.');

            return;
        }

        $password = Str::password(16);
        DB::transaction(function () use ($password) {
            $user = User::create(['name' => $this->name, 'email' => strtolower(trim($this->email)), 'password' => $password, 'is_active' => true]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($this->role);
        });

        $this->oneTimePassword = $password;
        $this->oneTimeFor = strtolower(trim($this->email));
        $this->showCreate = false;
    }

    public function deactivate(int $id): void
    {
        $this->guard();
        $user = User::query()->findOrFail($id);

        if ($user->is(Auth::user())) {
            $this->toast('You cannot deactivate your own account.', 'error');

            return;
        }

        $user->update(['is_active' => false]);
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        $this->toast("{$user->name} deactivated and signed out.");
    }

    public function reactivate(int $id): void
    {
        $this->guard();
        $user = User::query()->findOrFail($id);

        if (User::query()->role($user->roleName())->where('is_active', true)->whereKeyNot($id)->exists()) {
            $this->toast('Another active account already holds this role.', 'error');

            return;
        }

        $user->update(['is_active' => true]);
        $this->toast("{$user->name} reactivated.");
    }

    public function unlock(int $id): void
    {
        $this->guard();
        User::query()->findOrFail($id)->forceFill(['locked_until' => null, 'failed_login_attempts' => 0])->save();
        $this->toast('Account unlocked.');
    }

    /** PAKA-RANGI §5.4.1: a confirm popup, not wire:confirm. */
    public function confirmReset(int $id): void
    {
        $this->resetId = $id;
        $this->showReset = true;
    }

    public function resetPassword(): void
    {
        $this->guard();
        $user = User::query()->findOrFail($this->resetId);
        $this->reset(['showReset', 'resetId']);
        $password = Str::password(16);
        $user->forceFill(['password' => $password, 'locked_until' => null, 'failed_login_attempts' => 0])->save();
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        app(AuditLogger::class)->record($user, 'password_reset_by_chief_of_staff');

        $this->oneTimePassword = $password;
        $this->oneTimeFor = $user->email;
    }

    public function resetTwoFactor(int $id): void
    {
        $this->guard();
        $user = User::query()->findOrFail($id);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        app(AuditLogger::class)->record($user, 'two_factor_reset');
        $this->toast("{$user->name} must set up two-factor authentication again at next sign-in.");
    }

    public function render(): View
    {
        $users = User::query()->with('roles')->orderByDesc('is_active')->orderBy('name')->get();

        return view('livewire.users', [
            'slots' => collect(User::ROLES)->map(fn ($label, $role) => [
                'label' => $label,
                'active' => $users->first(fn (User $u) => $u->is_active && $u->roleName() === $role),
                'former' => $users->filter(fn (User $u) => ! $u->is_active && $u->roleName() === $role),
            ]),
        ]);
    }
}
