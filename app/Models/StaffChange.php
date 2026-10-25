<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A promotion, transfer or change of an officer's system access: who held
 * which department, designation and role, from when, why and who recorded it.
 * Written only by App\Services\StaffChanges; never edited or deleted.
 */
class StaffChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'staff_id', 'user_id', 'from_department_id', 'to_department_id', 'from_designation_id', 'to_designation_id',
        'from_role', 'to_role', 'effective_on', 'reason', 'reference', 'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Staff changes are never edited.'));
        static::deleting(fn () => throw new LogicException('Staff changes are never deleted.'));
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** @return BelongsTo<Department, $this> */
    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    /** @return BelongsTo<Designation, $this> */
    public function fromDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'from_designation_id');
    }

    /** @return BelongsTo<Designation, $this> */
    public function toDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'to_designation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public static function roleLabel(?string $role): string
    {
        return $role === null ? 'No system access' : (User::ROLES[$role] ?? $role);
    }
}
