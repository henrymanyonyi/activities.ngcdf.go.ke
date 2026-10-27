<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsLookup;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use Auditable;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use IsLookup;

    protected $fillable = ['name', 'code', 'manager_staff_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Staff, $this> */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    /**
     * The department's Manager (27 October 2026): an officer of the department on the staff
     * list. A position only: it gives no system access (the Head of Department is the account).
     *
     * @return BelongsTo<Staff, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'manager_staff_id');
    }

    /** @return HasMany<Activity, $this> */
    public function organisedActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'organising_department_id');
    }
}
