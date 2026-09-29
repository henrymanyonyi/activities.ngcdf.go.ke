<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FRD RP-02: the back-to-office report as received. */
class ReportReceipt extends Model
{
    use Auditable;

    protected $fillable = ['activity_id', 'received_on', 'outputs_achieved', 'findings', 'recommendations', 'document_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<ActivityDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ActivityDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
