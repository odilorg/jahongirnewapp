<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-app API key for the internal Telegram proxy API.
 *
 * Keys are stored as SHA-256 hashes (never plaintext). The plaintext key
 * is shown only once at creation time and cannot be retrieved afterward.
 *
 * @property int $id
 * @property string $name
 * @property string $key_hash
 * @property string $key_prefix
 * @property array|null $allowed_slugs
 * @property array|null $allowed_actions
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class TelegramServiceKey extends Model
{
    protected $table = 'telegram_service_keys';

    protected $fillable = [
        'name',
        'key_hash',
        'key_prefix',
        'allowed_slugs',
        'allowed_actions',
        'is_active',
        'last_used_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'allowed_slugs' => 'array',
        'allowed_actions' => 'array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function canAccessSlug(string $slug): bool
    {
        if ($this->allowed_slugs === null) {
            return true; // null = all slugs allowed
        }

        return in_array($slug, $this->allowed_slugs, true);
    }

    public function canPerformAction(string $action): bool
    {
        if ($this->allowed_actions === null) {
            return true; // null = all actions allowed
        }

        return in_array($action, $this->allowed_actions, true);
    }

    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Generate a new API key and return the plaintext (shown once only).
     * Stores the SHA-256 hash and prefix.
     */
    public static function generateKey(): array
    {
        $plaintext = 'tgsk_' . bin2hex(random_bytes(32)); // 69 chars
        $hash = hash('sha256', $plaintext);
        $prefix = substr($plaintext, 0, 12); // "tgsk_" + 7 hex chars

        return [
            'plaintext' => $plaintext,
            'hash' => $hash,
            'prefix' => $prefix,
        ];
    }

    /**
     * Find a service key by its plaintext value.
     */
    public static function findByKey(string $plaintext): ?self
    {
        $hash = hash('sha256', $plaintext);

        return static::where('key_hash', $hash)->first();
    }
}
