<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FRD CF-06: sign-ins, views, exports and prints. Written only by App\Services\AccessLogger. */
class AccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email', 'event', 'subject_type', 'subject_id', 'meta', 'ip_address', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
