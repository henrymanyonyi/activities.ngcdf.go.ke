<?php

namespace App\Models;

use Database\Factories\ScheduledStaffChangeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A change of an officer's position or access recorded to take effect on a later date.
 * Written and moved on only by App\Services\StaffChanges (scheduled → applied, failed
 * or cancelled); never deleted.
 */
class ScheduledStaffChange extends Model
{
    /** @use HasFactory<ScheduledStaffChangeFactory> */
    use HasFactory;

    public const SCHEDULED = 'scheduled';

    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['staff_id', 'effective_on', 'payload', 'status', 'outcome', 'staff_change_id', 'scheduled_by', 'cancelled_by', 'processed_at'];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Scheduled staff changes are never deleted.'));
    }

    /** @param  Builder<static>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::SCHEDULED);
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /** The access the change gives, as a label ("Keep their current access" when unchanged). */
    public function accessLabel(): string
    {
        $access = $this->payload['access'] ?? null;

        return match ($access) {
            'keep' => 'Keep their current access',
            default => StaffChange::roleLabel($access === 'none' ? null : $access),
        };
    }

    public function departmentName(): string
    {
        $id = $this->payload['department_id'] ?? null;

        return $id ? (Department::query()->find($id)?->name ?? 'Unknown department') : 'No department';
    }
}
