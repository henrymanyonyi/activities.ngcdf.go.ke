<?php

namespace App\Models;

use App\Enums\InstructionKind;
use App\Enums\InstructionStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\ActionInstructionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The CEO's instruction to Finance or Procurement to act on an approved memo.
 * Changes only through App\Services\ActionInstructions.
 */
class ActionInstruction extends Model
{
    use Auditable;

    /** @use HasFactory<ActionInstructionFactory> */
    use HasFactory;

    protected $fillable = [
        'activity_id', 'kind', 'department_id', 'note', 'status',
        'sent_by', 'sent_at', 'acknowledged_by', 'acknowledged_at',
        'completed_by', 'completed_at', 'completion_reference', 'completion_note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => InstructionKind::class,
            'status' => InstructionStatus::class,
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
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
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Instructions addressed to the user's department: a Head of Department sees only those.
     * isset(), because a user loaded without department_id (the CEO's account) must not fail.
     *
     * @param  Builder<static>  $query
     */
    public function scopeFor(Builder $query, User $user): void
    {
        if (! $user->can('instructions.action') || ! isset($user->department_id)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('department_id', $user->department_id);
    }

    public function isFor(User $user): bool
    {
        return $user->can('instructions.action') && isset($user->department_id) && (int) $this->department_id === (int) $user->department_id;
    }
}
