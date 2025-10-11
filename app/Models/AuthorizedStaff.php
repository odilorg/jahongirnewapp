<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorizedStaff extends Model
{
    protected $table = 'authorized_staff';

    protected $fillable = [
        'phone_number',
        'telegram_user_id',
        'telegram_username',
        'full_name',
        'role',
        'is_active',
        'last_active_at',
    ];

    protected $casts = [
        'telegram_user_id' => 'integer',
        'is_active' => 'boolean',
        'last_active_at' => 'datetime',
    ];

    // Relationships
    public function bookingRequests(): HasMany
    {
        return $this->hasMany(StaffBookingRequest::class, 'staff_id');
    }

    public function managedBookings(): HasMany
    {
        return $this->hasMany(BotManagedBooking::class, 'created_by_staff_id');
    }

    // Check if phone number is authorized
    public static function isAuthorized(string $phoneNumber): bool
    {
        return self::where('phone_number', $phoneNumber)
            ->where('is_active', true)
            ->exists();
    }

    // Find by Telegram user ID
    public static function findByTelegramId(int $telegramUserId): ?self
    {
        return self::where('telegram_user_id', $telegramUserId)
            ->where('is_active', true)
            ->first();
    }

    // Update last active timestamp
    public function touchLastActive(): void
    {
        $this->update(['last_active_at' => now()]);
    }
}
