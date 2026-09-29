<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Key/value store behind App\Services\AppSettings. */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];
}
