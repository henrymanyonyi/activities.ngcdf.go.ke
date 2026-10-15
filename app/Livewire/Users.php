<?php

namespace App\Livewire;

use App\Livewire\Concerns\RunsActions;
use App\Models\Department;
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
 * FRD CF-01 / CF-04: one active holder per role in User::ROLES (one per
 * department for Heads of Department), managed by the Chief of Staff or the
 * System Administrator. Accounts are deactivated, never deleted.
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

    public string $department_id = '';

    public bool $showDepartment = false;

    public ?int $departmentUserId = null;

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
        $this->reset(['name', 'email', 'department_id']);
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
            'department_id' => [$this->isDepartmental($this->role) ? 'required' : 'nullable', 'exists:departments,id'],
        ], [], ['department_id' => 'department']);

        if ($holder = $this->activeHolder($this->role, $this->isDepartmental($this->role) ? (int) $this->department_id : null)) {
            $this->addError('role', $this->isDepartmental($this->role)
                ? "{$holder->name} is already the active Head of Department for this department. Deactivate that account first."
                : 'There is already an active '.User::ROLES[$this->role].' account. Deactivate it first.');

            return;
        }

        $password = Str::password(16);
        DB::transaction(function () use ($password) {
            $user = User::create(['name' => $this->name, 'email' => strtolower(trim($this->email)), 'department_id' => $this->isDepartmental($this->role) ? (int) $this->department_id : null, 'password' => $password, 'is_active' => true]);
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

        if ($this->activeHolder($user->roleName(), $this->isDepartmental($user->roleName()) ? $user->department_id : null, $id)) {
            $this->toast($this->isDepartmental($user->roleName()) ? 'Another active account is already Head of this department.' : 'Another active account already holds this role.', 'error');

            return;
        }

        $user->update(['is_active' => true]);
        $this->toast("{$user->name} reactivated.");
    }

    public function openDepartment(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->departmentUserId = $id;
        $this->department_id = (string) $user->department_id;
        $this->resetErrorBag();
        $this->showDepartment = true;
    }

    /** Move a Head of Department to another department; they then see only that department's memos. */
    public function saveDepartment(): void
    {
        $this->guard();
        $this->validate(['department_id' => ['required', 'exists:departments,id']], [], ['department_id' => 'department']);
        $user = User::query()->role(User::ROLE_HOD)->findOrFail($this->departmentUserId);

        if ($user->is_active && ($holder = $this->activeHolder(User::ROLE_HOD, (int) $this->department_id, $user->id))) {
            $this->addError('department_id', "{$holder->name} is already the active Head of that department.");

            return;
        }

        $user->update(['department_id' => (int) $this->department_id]);
        $this->reset(['showDepartment', 'departmentUserId', 'department_id']);
        $this->toast("{$user->name} is now linked to {$user->fresh()->department?->name}.");
    }

    private function isDepartmental(?string $role): bool
    {
        return in_array($role, User::DEPARTMENTAL_ROLES, true);
    }

    /** The active account holding the role (in the department, for a departmental role). */
    private function activeHolder(?string $role, ?int $departmentId = null, ?int $except = null): ?User
    {
        return User::query()->role($role)->where('is_active', true)
            ->when($this->isDepartmental($role), fn ($q) => $q->where('department_id', $departmentId))
            ->when($except, fn ($q, $id) => $q->whereKeyNot($id))
            ->first();
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
        $users = User::query()->with(['roles', 'department'])->orderByDesc('is_active')->orderBy('name')->get();

        return view('livewire.users', [
            'heads' => $users->filter(fn (User $u) => $u->roleName() === User::ROLE_HOD)->sortBy([['is_active', 'desc'], [fn ($a, $b) => strcmp((string) $a->department?->name, (string) $b->department?->name)]])->values(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'slots' => collect(User::ROLES)->except(User::DEPARTMENTAL_ROLES)->map(fn ($label, $role) => [
                'label' => $label,
                'active' => $users->first(fn (User $u) => $u->is_active && $u->roleName() === $role),
                'former' => $users->filter(fn (User $u) => ! $u->is_active && $u->roleName() === $role),
            ]),
        ]);
    }
}
