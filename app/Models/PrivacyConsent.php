<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record that someone agreed to a given version of the NG-CDF Board Data
 * Privacy Statement. Written only by PrivacyConsentService; never updated.
 */
class PrivacyConsent extends Model
{
    public const TYPE_AUTHENTICATED = 'authenticated';

    public const TYPE_ANONYMOUS = 'anonymous';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['consent_token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
        ];
    }
}
