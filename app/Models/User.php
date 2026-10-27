<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Notifications\AccountAccessGranted;
use App\Services\AccessLogger;
use App\Support\MobileNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Auditable;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasRoles;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * FRD 4 / CF-01: the three accounts of the Office of the CEO, plus a System
     * Administrator for settings, configuration and logs only (no activity
     * data), approved by the CEO on 11 October 2026. One account per role,
     * except Heads of Department: one per department, seeing only their own
     * department's memos (activity memo workflow, October 2026).
     */
    public const ROLE_CEO = 'ceo';

    public const ROLE_CHIEF_OF_STAFF = 'chief_of_staff';

    public const ROLE_ASSISTANT_CHIEF_OF_STAFF = 'assistant_chief_of_staff';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_HOD = 'hod';

    /** @var array<string, string> role => label */
    public const ROLES = [
        self::ROLE_CEO => 'Chief Executive Officer',
        self::ROLE_CHIEF_OF_STAFF => 'Chief of Staff',
        self::ROLE_ASSISTANT_CHIEF_OF_STAFF => 'Assistant Chief of Staff',
        self::ROLE_ADMIN => 'System Administrator',
        self::ROLE_HOD => 'Head of Department',
    ];

    /** Roles held by one active account per department instead of one in total. */
    public const DEPARTMENTAL_ROLES = [self::ROLE_HOD];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'department_id',
        'staff_id',
        'password',
        'is_active',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Emails the account a link to set its own password (App\Notifications\AccountAccessGranted),
     * naming the access when it has just been given. Logged in the access log.
     */
    public function sendPasswordLink(?string $access = null): void
    {
        $this->notify(new AccountAccessGranted(Password::broker()->createToken($this), $access));
        app(AccessLogger::class)->log(AccessLogger::PASSWORD_LINK_SENT, meta: array_filter(['access' => $access, 'sent_by' => auth()->id()]), userId: $this->id, email: $this->email);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function roleName(): ?string
    {
        return $this->roles->first()?->name;
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The officer on the staff list this account was made from, if any.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * Where the action-needed SMS goes: the account's own mobile number, else its officer's on the staff list.
     * isset(), because a user loaded without these columns must not fail.
     */
    public function mobileNumber(): ?string
    {
        $phone = isset($this->phone) ? $this->phone : null;
        if (blank($phone) && isset($this->staff_id)) {
            $phone = ($this->relationLoaded('staff') ? $this->staff : $this->staff()->first())?->phone;
        }

        return MobileNumber::normalise($phone);
    }

    /**
     * How the officer signs: their substantive designation on the staff list and their department, e.g.
     * "Manager, ICT". Falls back to the role when the account is not linked to an officer with a designation.
     */
    public function signingDesignation(): string
    {
        $designation = isset($this->staff_id) ? Staff::query()->with('designation')->find($this->staff_id)?->designation?->name : null;
        $department = isset($this->department_id) ? Department::query()->find($this->department_id)?->name : null;

        return ($designation ?: ($this->isHeadOfDepartment() ? 'Head of Department' : $this->roleLabel())).($department ? ", {$department}" : '');
    }

    public function isHeadOfDepartment(): bool
    {
        return $this->hasRole(self::ROLE_HOD);
    }

    /** The first page this user may open: the dashboard, memos for a Head of Department, or for the System Administrator, settings or the log. */
    public function homeRoute(): string
    {
        return match (true) {
            $this->can('activities.view') => 'dashboard',
            $this->can('memos.originate') => 'memos.index',
            $this->can('reference.manage') => 'settings.reference',
            $this->can('users.manage') => 'users.index',
            $this->can('audit.view') => 'audit.index',
            default => 'profile.show',
        };
    }

    public function roleLabel(): string
    {
        $role = $this->roles->first();

        return $role?->label ?? ($role ? Str::headline($role->name) : 'User');
    }
}
