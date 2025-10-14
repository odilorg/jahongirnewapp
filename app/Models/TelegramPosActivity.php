<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramPosActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'telegram_user_id',
        'action',
        'details',
        'ip_address',
    ];

    protected $casts = [
        'telegram_user_id' => 'integer',
    ];

    /**
     * Get the user that performed this activity
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log an activity
     */
    public static function log($user, string $action, $details = null, $telegramUserId = null): self
    {
        return self::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'telegram_user_id' => $telegramUserId,
            'action' => $action,
            'details' => is_array($details) ? json_encode($details) : $details,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Scope for specific action
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope for user activities
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for today's activities
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}

