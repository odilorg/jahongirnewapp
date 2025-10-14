<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramPosSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_user_id',
        'chat_id',
        'user_id',
        'state',
        'data',
        'language',
        'last_activity_at',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'telegram_user_id' => 'integer',
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
     * Check if the session has expired
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }

        return now()->greaterThan($this->expires_at);
    }

    /**
     * Update the last activity timestamp and extend expiry
     */
    public function updateActivity(): void
    {
        $timeoutMinutes = config('services.telegram_pos_bot.session_timeout', 15);
        
        $this->update([
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes($timeoutMinutes),
        ]);
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
     * Scope for active sessions
     */
    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now())
            ->orWhereNull('expires_at');
    }

    /**
     * Scope for expired sessions
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now())
            ->whereNotNull('expires_at');
    }
}

