<?php

namespace App\Models\Concerns;

use App\Services\Activity\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Model-event activity logging (board 2026-10-31): every create / update / delete of a
 * model that uses this trait becomes an Activity Log row with the changed attributes
 * (old → new), minus secrets and heartbeat-style noise.
 *
 * The model may declare:
 *   public const ACTIVITY_CATEGORY = 'sales';            // ActivityLog::CATEGORIES key
 *   public const ACTIVITY_IGNORE = ['last_seen_at'];     // attributes whose change alone is not logged
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn (Model $m) => $m->logActivity('created'));
        static::updated(fn (Model $m) => $m->logActivity('updated'));
        static::deleted(fn (Model $m) => $m->logActivity(method_exists($m, 'isForceDeleting') && ! $m->isForceDeleting() ? 'archived' : 'deleted'));
        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $m) => $m->logActivity('restored'));
        }
    }

    public function activityCategory(): string
    {
        return defined(static::class . '::ACTIVITY_CATEGORY') ? static::ACTIVITY_CATEGORY : 'master';
    }

    /** Human name of the model: "Sales invoice", "Member contract" … */
    public function activityNoun(): string
    {
        return Str::of(class_basename($this))->snake(' ')->ucfirst()->toString();
    }

    protected function logActivity(string $event): void
    {
        if (! ActivityLogger::$enabled) {
            return;
        }
        $ignore = array_merge(
            ['updated_at', 'created_at', 'remember_token'],
            defined(static::class . '::ACTIVITY_IGNORE') ? static::ACTIVITY_IGNORE : [],
        );
        $details = [];
        if ($event === 'updated') {
            $changes = array_diff_key($this->getChanges(), array_flip($ignore));
            if (! $changes) {
                return;   // only noise changed (timestamps, heartbeat fields)
            }
            foreach ($changes as $k => $new) {
                $details['changes'][$k] = ['from' => $this->getOriginal($k), 'to' => $new];
            }
            $what = implode(', ', array_keys($changes));
            $summary = $this->activityNoun() . ' ' . ActivityLogger::labelOf($this) . ' updated (' . Str::limit($what, 120, '…') . ')';
        } elseif ($event === 'created') {
            $details['attributes'] = array_diff_key($this->getAttributes(), array_flip($ignore));
            $summary = $this->activityNoun() . ' ' . ActivityLogger::labelOf($this) . ' created';
        } else {
            $summary = $this->activityNoun() . ' ' . ActivityLogger::labelOf($this) . ' ' . $event;
        }

        ActivityLogger::log($this->activityCategory(), $event, $summary, [
            'subject' => $this,
            'details' => $details ?: null,
        ]);
    }
}
