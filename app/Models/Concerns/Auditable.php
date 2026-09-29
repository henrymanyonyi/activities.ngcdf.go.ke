<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit_logs row for every create, update, delete and restore of the
 * model. Updates record only the attributes that changed, old and new.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            app(AuditLogger::class)->record($model, 'created', null, $model->getAttributes());
        });

        static::updated(function (Model $model): void {
            $changes = collect($model->getChanges())->except(['updated_at'])->all();
            if ($changes === []) {
                return;
            }

            $old = collect($changes)->mapWithKeys(fn ($value, $key) => [$key => $model->getOriginal($key)])->all();
            app(AuditLogger::class)->record($model, 'updated', $old, $changes);
        });

        static::deleted(function (Model $model): void {
            app(AuditLogger::class)->record($model, 'deleted', $model->getAttributes());
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model): void {
                app(AuditLogger::class)->record($model, 'restored');
            });
        }
    }
}
