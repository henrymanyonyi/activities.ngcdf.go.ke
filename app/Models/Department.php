<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsLookup;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use Auditable;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use IsLookup;

    protected $fillable = ['name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Staff, $this> */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    /** @return HasMany<Activity, $this> */
    public function organisedActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'organising_department_id');
    }
}
