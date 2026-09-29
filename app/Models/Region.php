<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    use Auditable;
    use IsLookup;

    protected $fillable = ['name', 'code', 'smart_cluster_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Office, $this> */
    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }
}
