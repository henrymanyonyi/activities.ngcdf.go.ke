<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FRD MD-04: an activity may be held in more than one place. */
class ActivityLocation extends Model
{
    use Auditable;

    protected $fillable = ['activity_id', 'region_id', 'county_id', 'constituency_id', 'venue'];

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<County, $this> */
    public function county(): BelongsTo
    {
        return $this->belongsTo(County::class);
    }

    /** @return BelongsTo<Constituency, $this> */
    public function constituency(): BelongsTo
    {
        return $this->belongsTo(Constituency::class);
    }

    public function label(): string
    {
        return collect([$this->venue, $this->constituency?->name, $this->county?->name, $this->region?->name])
            ->filter()
            ->unique()
            ->implode(', ');
    }
}
