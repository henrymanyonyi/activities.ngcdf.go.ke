<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FRD CD-04: a CEO directive or action item, tracked to completion. */
class Directive extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'activity_id', 'body', 'responsible_department_id', 'due_on', 'status',
        'completion_note', 'completed_at', 'completed_by', 'on_ceo_instruction', 'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'on_ceo_instruction' => 'boolean',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'responsible_department_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN);
    }

    /** @param  Builder<static>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereNotNull('due_on')->whereDate('due_on', '<', today());
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->due_on?->isBefore(today());
    }
}
