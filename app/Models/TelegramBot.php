<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a registered Telegram bot.
 *
 * Secrets (token, webhook_secret) live in TelegramBotSecret — never on this model.
 * Decryption is handled exclusively by BotSecretProviderInterface implementations.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $bot_username
 * @property string|null $description
 * @property BotStatus $status
 * @property BotEnvironment $environment
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $last_error_at
 * @property string|null $last_error_code
 * @property string|null $last_error_summary
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class TelegramBot extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'telegram_bots';

    protected $fillable = [
        'slug',
        'name',
        'bot_username',
        'description',
        'status',
        'environment',
        'metadata',
        'last_used_at',
        'last_error_at',
        'last_error_code',
        'last_error_summary',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => BotStatus::class,
        'environment' => BotEnvironment::class,
        'metadata' => 'array',
        'last_used_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    /**
     * All secret versions for this bot (newest first by default via scope).
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(TelegramBotSecret::class);
    }

    /**
     * The single active secret for this bot.
     */
    public function activeSecret(): HasOne
    {
        return $this->hasOne(TelegramBotSecret::class)
            ->where('status', 'active')
            ->latestOfMany('version');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeActive($query): void
    {
        $query->where('status', BotStatus::Active);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeForEnvironment($query, BotEnvironment $env): void
    {
        $query->where('environment', $env);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeBySlug($query, string $slug): void
    {
        $query->where('slug', $slug);
    }

    // ──────────────────────────────────────────────
    // Domain Methods
    // ──────────────────────────────────────────────

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function markUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public function markError(string $code, string $summary): void
    {
        $this->update([
            'last_error_at' => now(),
            'last_error_code' => $code,
            'last_error_summary' => $summary,
        ]);
    }
}
