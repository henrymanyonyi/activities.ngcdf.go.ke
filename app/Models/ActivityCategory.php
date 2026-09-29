<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityCategory extends Model
{
    use Auditable;
    use IsLookup;

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<ActivityType, $this> */
    public function types(): HasMany
    {
        return $this->hasMany(ActivityType::class)->orderBy('name');
    }
}
