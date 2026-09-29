<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Shared behaviour for name-keyed reference lists (departments, designations...).
 * Names arrive typed by hand in uploaded lists, so matching is case- and
 * whitespace-insensitive and a new name creates a new row (decision Q6).
 */
trait IsLookup
{
    public static function normaliseName(?string $name): string
    {
        return Str::of((string) $name)->squish()->toString();
    }

    /**
     * Find by name ignoring case, or create it. Returns null for a blank name.
     *
     * @param  array<string, mixed>  $extra  attributes used only when creating
     */
    public static function resolveByName(?string $name, array $extra = []): ?static
    {
        $name = static::normaliseName($name);
        if ($name === '') {
            return null;
        }

        $existing = static::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        return $existing ?? static::create(['name' => $name] + $extra);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('name');
    }
}
