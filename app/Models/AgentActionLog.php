<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit + idempotency ledger row for a single tour-agent action attempt.
 * Written by AgentActionDispatcher; never by the external runner directly.
 */
class AgentActionLog extends Model
{
    protected $table = 'agent_action_log';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_SIMULATED = 'simulated';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'booking_inquiry_id',
        'action',
        'params',
        'actor',
        'approval_token',
        'idempotency_key',
        'status',
        'reason',
        'result',
        'preview',
        'error',
    ];

    protected $casts = [
        'params' => 'array',
        'result' => 'array',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class, 'booking_inquiry_id');
    }
}
