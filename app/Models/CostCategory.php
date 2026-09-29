<?php

namespace App\Models;

use App\Enums\CostScope;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CostCategory extends Model
{
    use Auditable;

    protected $fillable = ['name', 'code', 'default_scope', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'default_scope' => CostScope::class,
            'is_active' => 'boolean',
        ];
    }

    /** DSA, travel or other, for the concept's DSA / travel / total columns (BR-03). */
    public function group(): string
    {
        return match ($this->code) {
            'dsa' => 'dsa',
            'travel', 'fuel', 'vehicle_hire' => 'travel',
            default => 'other',
        };
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
