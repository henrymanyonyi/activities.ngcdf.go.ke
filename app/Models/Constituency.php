<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One of the 290 constituencies. Snapshot of Smart NG-CDF's geography (GeographySeeder). */
class Constituency extends Model
{
    protected $fillable = ['code', 'name', 'county_id', 'region_id'];

    /** @return BelongsTo<County, $this> */
    public function county(): BelongsTo
    {
        return $this->belongsTo(County::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
