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

    public function rates(): HasMany
    {
        return $this->hasMany(AccommodationRate::class)->orderBy('sort_order')->orderBy('min_occupancy');
    }

    /**
     * Resolve the best matching rate for a given number of guests.
     *
     * For per_person rates: finds the tier where min_occ ≤ guests ≤ max_occ
     * (or min_occ ≤ guests if max_occ is null).
     *
     * For per_room rates: returns null — operator must select room type manually.
     *
     * @return AccommodationRate|null
     */
    public function costForGuests(int $guests): ?AccommodationRate
    {
        return $this->rates()
            ->where('rate_type', AccommodationRate::TYPE_PER_PERSON)
            ->where('is_active', true)
            ->where('min_occupancy', '<=', $guests)
            ->where(function ($q) use ($guests) {
                $q->where('max_occupancy', '>=', $guests)
                  ->orWhereNull('max_occupancy');
            })
            ->orderByDesc('min_occupancy')
            ->first();
    }

    /**
     * Does this accommodation use per-person pricing?
     */
    public function isPerPersonPricing(): bool
    {
        return $this->rates()
            ->where('rate_type', AccommodationRate::TYPE_PER_PERSON)
            ->where('is_active', true)
            ->exists();
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

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'supplier_id')
            ->where('supplier_type', 'accommodation');
    }

    public function payments()
    {
        return SupplierPayment::forSupplier('accommodation', $this->id)->recorded();
    }

    public function totalOwed(): float
    {
        return (float) InquiryStay::where('accommodation_id', $this->id)
            ->whereNotNull('total_accommodation_cost')
            ->sum('total_accommodation_cost');
    }

    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function outstandingBalance(): float
    {
        return $this->totalOwed() - $this->totalPaid();
    }
}
