<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SecretStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned secret (token + optional webhook_secret) for a TelegramBot.
 *
 * token_encrypted and webhook_secret_encrypted are stored as Laravel Crypt
 * ciphertext. This model intentionally does NOT expose decrypted values
 * via accessors or casts. Decryption is the responsibility of
 * BotSecretProviderInterface implementations.
 *
 * @property int $id
 * @property int $telegram_bot_id
 * @property int $version
 * @property string $token_encrypted
 * @property string|null $webhook_secret_encrypted
 * @property SecretStatus $status
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TelegramBotSecret extends Model
{
    use HasFactory;

    protected $table = 'telegram_bot_secrets';

    protected $fillable = [
        'telegram_bot_id',
        'version',
        'token_encrypted',
        'webhook_secret_encrypted',
        'status',
        'activated_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'status' => SecretStatus::class,
        'activated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Encrypted columns must never appear in serialization (toArray, toJson, queue payloads).
     */
    protected $hidden = [
        'token_encrypted',
        'webhook_secret_encrypted',
    ];

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeActive($query): void
    {
        $query->where('status', SecretStatus::Active);
    }

    // ──────────────────────────────────────────────
    // Domain Methods
    // ──────────────────────────────────────────────

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function markRevoked(): void
    {
        $this->update([
            'status' => SecretStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    public function activate(): void
    {
        $this->update([
            'status' => SecretStatus::Active,
            'activated_at' => now(),
        ]);
    }
}
