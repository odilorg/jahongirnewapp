<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lodging supplier (yurt camp, homestay, hotel, guesthouse).
 *
 * Mirror of Driver / Guide — a normalised supplier the operator picks
 * when assigning where guests sleep on a tour. Phase 6 will attach a
 * RateCard relation for tiered pricing.
 */
class Accommodation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'location',
        'contact_name',
        'phone_primary',
        'phone_secondary',
        'email',
        'telegram_chat_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function stays(): HasMany
    {
        return $this->hasMany(InquiryStay::class);
    }

    /**
     * Display label used by Filament Selects and the dispatch templates.
     * Includes location when present so "Aydarkul Yurt Camp · Aydarkul Lake"
     * disambiguates from other camps in the dropdown.
     */
    public function getFullNameAttribute(): string
    {
        return $this->location
            ? "{$this->name} · {$this->location}"
            : (string) $this->name;
    }
}
