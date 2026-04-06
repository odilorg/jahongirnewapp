<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit log for Driver and Guide mutations.
 *
 * Written by DriverService and GuideService on every state change.
 * No FK constraint on entity_id — rows survive hard-delete of the entity.
 */
class StaffAuditLog extends Model
{
    public const UPDATED_AT = null; // append-only, no updated_at

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'changes',
        'actor',
    ];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime',
    ];

    // ── Convenience factory ──────────────────────────────────────────────────

    public static function record(
        string $entityType,
        int    $entityId,
        string $action,
        ?array $changes,
        string $actor,
    ): void {
        static::create([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'changes'     => $changes,
            'actor'       => $actor,
        ]);
    }
}
