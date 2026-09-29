<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of the 47 counties. Snapshot of Smart NG-CDF's geography (GeographySeeder). */
class County extends Model
{
    use Auditable;

    protected $fillable = ['code', 'name', 'dsa_destination_category'];

    /** @return HasMany<Constituency, $this> */
    public function constituencies(): HasMany
    {
        return $this->hasMany(Constituency::class)->orderBy('name');
    }
}
