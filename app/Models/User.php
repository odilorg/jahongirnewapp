<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Panel;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Testing\Fluent\Concerns\Has;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $role
 * @method bool hasRole(string $role)
 * @method bool hasAnyRole(array $roles)
 */

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;


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

    protected $guard_name = 'web';   // add this if you use multiple guards

    protected $fillable = [
        'name',
        'email',
        'password',
        'role'
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
}
