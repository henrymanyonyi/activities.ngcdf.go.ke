<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name'];

    /** @return BelongsToMany<Activity, $this> */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class);
    }

    /**
     * Turn "Proposal review, kakamega ,  M&E" into tag ids, creating new tags.
     *
     * @return list<int>
     */
    public static function idsFromString(?string $input): array
    {
        return collect(explode(',', (string) $input))
            ->map(fn (string $name) => Str::of($name)->squish()->lower()->limit(60, '')->toString())
            ->filter()
            ->unique()
            ->map(fn (string $name) => static::firstOrCreate(['name' => $name])->id)
            ->values()
            ->all();
    }
}
