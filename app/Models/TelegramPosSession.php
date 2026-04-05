<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramPosSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'user_id',
        'state',
        'data',
        'language',
    ];

    protected $casts = [
        'data' => 'array',
        'chat_id' => 'integer',
    ];

    /**
     * Get the user that owns this session
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the session has expired.
     * Uses updated_at as the activity timestamp (schema has no last_activity_at/expires_at).
     * Idle sessions (main_menu/idle) expire after the configured timeout;
     * active financial sessions expire after a hard 4-hour TTL.
     */
    public function isExpired(?int $timeoutMinutes = null): bool
    {
        $timeoutMinutes ??= config('services.telegram_pos_bot.session_timeout', 240);
        return $this->updated_at && $this->updated_at->addMinutes($timeoutMinutes)->isPast();
    }

    /**
     * Touch the session so updated_at reflects the latest activity.
     */
    public function updateActivity(?int $timeoutMinutes = null): void
    {
        $this->touch();
    }

    /**
     * Set the session state
     */
    public function setState(string $state): void
    {
        $this->update(['state' => $state]);
    }

    /**
     * Get data by key
     */
    public function getData(string $key, $default = null)
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Set data by key
     */
    public function setData(string $key, $value): void
    {
        $data = $this->data ?? [];
        data_set($data, $key, $value);
        $this->update(['data' => $data]);
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->user_id !== null && $this->state === 'authenticated';
    }

    /**
     * Scope for active sessions (updated within the configured timeout window).
     */
    public function scopeActive($query)
    {
        $minutes = config('services.telegram_pos_bot.session_timeout', 240);
        return $query->where('updated_at', '>', now()->subMinutes($minutes));
    }

    /**
     * Scope for expired sessions.
     */
    public function scopeExpired($query)
    {
        $minutes = config('services.telegram_pos_bot.session_timeout', 240);
        return $query->where('updated_at', '<=', now()->subMinutes($minutes));
    }
}

