<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Panel;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method bool hasRole(string $role)
 * @method bool hasAnyRole(array $roles)
 */

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $guard_name = 'web';

    public function canAccessPanel(Panel $panel): bool
    {
        // Allow access if user has any role or is super admin
        return $this->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']) ||
               $this->hasRole(config('filament-shield.super_admin.name'));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'telegram_user_id',
        'telegram_username',
        'last_active_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'telegram_user_id' => 'integer',
        'last_active_at' => 'datetime',
    ];

    // Role check methods now use Spatie Permission
    // These methods are provided by HasRoles trait

    /**
     * Get all shifts for this user
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(CashierShift::class);
    }

    /**
     * Get open shifts for this user
     */
    public function openShifts(): HasMany
    {
        return $this->hasMany(CashierShift::class)->where('status', 'open');
    }

    /**
     * Get closed shifts for this user
     */
    public function closedShifts(): HasMany
    {
        return $this->hasMany(CashierShift::class)->where('status', 'closed');
    }

    /**
     * Get transactions created by this user
     */
    public function createdTransactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class, 'created_by');
    }

    /**
     * Get all locations assigned to this user
     */
    public function locations()
    {
        return $this->belongsToMany(Location::class)->withTimestamps();
    }

    /**
     * Get assigned location IDs for this user
     */
    public function getAssignedLocationIds(): array
    {
        return $this->locations()->pluck('locations.id')->toArray();
    }

    /**
     * Check if user is assigned to a specific location
     */
    public function isAssignedToLocation(int $locationId): bool
    {
        return $this->locations()->where('locations.id', $locationId)->exists();
    }

    /**
     * Check if user has any open shifts
     */
    public function hasOpenShifts(): bool
    {
        return $this->openShifts()->exists();
    }

    /**
     * Get current open shift for this user
     */
    public function getCurrentOpenShift(): ?CashierShift
    {
        return $this->openShifts()->first();
    }

    /**
     * Check if phone number is authorized for Telegram bot
     */
    public static function isPhoneAuthorized(string $phoneNumber): bool
    {
        return self::where('phone_number', $phoneNumber)->exists();
    }

    /**
     * Find user by Telegram user ID
     */
    public static function findByTelegramId(int $telegramUserId): ?self
    {
        return self::where('telegram_user_id', $telegramUserId)->first();
    }

    /**
     * Update last active timestamp
     */
    public function touchLastActive(): void
    {
        $this->update(['last_active_at' => now()]);
    }
}
