<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;

class Programme extends Model
{
    use Auditable;
    use IsLookup;

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
