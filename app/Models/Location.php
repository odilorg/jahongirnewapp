<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'name',
        'status',
        'description',
    ];

    protected $casts = [
        'hotel_id' => 'integer',
    ];

    /**
     * Get the hotel that owns this location
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * Get all cash drawers at this location
     */
    public function cashDrawers(): HasMany
    {
        return $this->hasMany(CashDrawer::class);
    }

    /**
     * Get all shifts at this location (through cash drawers)
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(CashierShift::class);
    }

    /**
     * Get all transactions at this location (through shifts)
     */
    public function transactions()
    {
        return $this->hasManyThrough(CashTransaction::class, CashierShift::class);
    }

    /**
     * Get all users (cashiers) assigned to this location
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Get all cashiers assigned to this location
     */
    public function cashiers()
    {
        return $this->belongsToMany(User::class)
            ->whereHas('roles', function ($query) {
                $query->where('name', 'cashier');
            })
            ->withTimestamps();
    }

    /**
     * Scope for active locations
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive locations
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Check if location is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if location is inactive
     */
    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }
}
