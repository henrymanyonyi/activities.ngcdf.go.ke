<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use Auditable;

    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'staff';

    protected $fillable = [
        'staff_number',
        'name',
        'email',
        'phone',
        'department_id',
        'designation_id',
        'job_grade',
        'office_id',
        'is_active',
        'joined_on',
        'exited_on',
        'smart_hq_staff_id',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_on' => 'date',
            'exited_on' => 'date',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return HasMany<ActivityParticipant, $this> */
    public function participations(): HasMany
    {
        return $this->hasMany(ActivityParticipant::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('staff_number', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%"));
    }
}
