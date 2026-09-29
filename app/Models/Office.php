<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Office extends Model
{
    use Auditable;
    use IsLookup;

    protected $fillable = ['name', 'region_id', 'is_headquarters', 'is_active'];

    protected function casts(): array
    {
        return ['is_headquarters' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
