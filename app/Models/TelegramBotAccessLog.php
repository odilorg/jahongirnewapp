<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessAction;
use App\Enums\AccessResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log for Telegram bot access and operations.
 *
 * This model intentionally:
 * - Has no $fillable (uses $guarded = [] with explicit column control in the logger)
 * - Has no updated_at (append-only: CONST_UPDATED_AT = null)
 * - Has no factory (logs are written by BotAuditLogger, not created in isolation)
 * - Has no mutators or delete methods
 *
 * Query this model for audit trails. Never update or delete rows.
 *
 * @property int $id
 * @property int|null $telegram_bot_id
 * @property int|null $actor_user_id
 * @property string|null $actor_type
 * @property string|null $actor_identifier
 * @property string|null $service_name
 * @property AccessAction $action
 * @property AccessResult $result
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 */
class TelegramBotAccessLog extends Model
{
    /** Append-only: disable updated_at column. */
    public const UPDATED_AT = null;

    protected $table = 'telegram_bot_access_logs';

    /**
     * All columns are writable internally. Access control is enforced
     * by the BotAuditLoggerInterface — not by the model's $fillable.
     */
    protected $guarded = [];

    protected $casts = [
        'action' => AccessAction::class,
        'result' => AccessResult::class,
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeForBot($query, int $botId): void
    {
        $query->where('telegram_bot_id', $botId);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeForAction($query, AccessAction $action): void
    {
        $query->where('action', $action);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeFailures($query): void
    {
        $query->where('result', '!=', AccessResult::Success);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeSecretAccesses($query): void
    {
        $query->whereIn('action', [
            AccessAction::TokenRead,
            AccessAction::WebhookSecretRead,
            AccessAction::TokenRevealed,
        ]);
    }
}
