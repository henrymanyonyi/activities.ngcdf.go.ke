<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;

class Designation extends Model
{
    use Auditable;
    use IsLookup;

    protected $fillable = ['name', 'job_group', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
