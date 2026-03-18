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

    /**
     * Encrypted columns (token_encrypted, webhook_secret_encrypted) are
     * intentionally excluded from $fillable. They must be set explicitly
     * via $model->token_encrypted = ... to prevent accidental mass-assignment
     * from untrusted input. Factories and the BotSecretProvider set them directly.
     */
    protected $fillable = [
        'telegram_bot_id',
        'version',
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
     * Encrypted columns must never appear in serialization (toArray, toJson,
     * queue payloads, API responses, logs, or exception context).
     *
     * This is the last line of defense. Even if someone calls ->makeVisible(),
     * the raw ciphertext is what leaks — not the plaintext. But the intent is
     * that these columns never leave the model layer at all.
     */
    protected $hidden = [
        'token_encrypted',
        'webhook_secret_encrypted',
    ];

    /**
     * Guard against ->makeVisible() or ->append() re-exposing encrypted fields.
     * There are no accessors for decrypted values on this model — by design.
     * If you need the decrypted token, use BotSecretProviderInterface.
     */
    protected $appends = [];

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
