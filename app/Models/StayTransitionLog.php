<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit record for check-in / check-out transitions.
 *
 * One row per successful transition — never updated, never deleted.
 * Failed/blocked transitions are not recorded (no status change occurred).
 */
class StayTransitionLog extends Model
{
    // Immutable audit table — no updates or soft deletes
    public const UPDATED_AT = null;

    protected $fillable = [
        'beds24_booking_id',
        'actor_user_id',
        'action',
        'old_status',
        'new_status',
        'source',
    ];

    protected $casts = [
        'actor_user_id' => 'integer',
        'created_at'    => 'datetime',
    ];

    /**
     * Convenience factory used by the Stay services.
     */
    public static function record(
        string $bookingId,
        int    $actorId,
        string $action,
        string $oldStatus,
        string $newStatus,
        string $source,
    ): self {
        return self::create([
            'beds24_booking_id' => $bookingId,
            'actor_user_id'     => $actorId,
            'action'            => $action,
            'old_status'        => $oldStatus,
            'new_status'        => $newStatus,
            'source'            => $source,
        ]);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
