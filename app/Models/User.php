<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
     * FRD 4 / CF-01: exactly three accounts, one per role. No other role exists.
     */
    public const ROLE_CEO = 'ceo';

    public const ROLE_CHIEF_OF_STAFF = 'chief_of_staff';

    public const ROLE_ASSISTANT_CHIEF_OF_STAFF = 'assistant_chief_of_staff';

    /** @var array<string, string> role => label */
    public const ROLES = [
        self::ROLE_CEO => 'Chief Executive Officer',
        self::ROLE_CHIEF_OF_STAFF => 'Chief of Staff',
        self::ROLE_ASSISTANT_CHIEF_OF_STAFF => 'Assistant Chief of Staff',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
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

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function roleName(): ?string
    {
        return $this->roles->first()?->name;
    }

    public function roleLabel(): string
    {
        $role = $this->roles->first();

        return $role?->label ?? ($role ? Str::headline($role->name) : 'User');
    }
}
