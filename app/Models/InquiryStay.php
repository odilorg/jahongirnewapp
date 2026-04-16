<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg of a tour's lodging — links a BookingInquiry to an
 * Accommodation with stay-specific metadata (date, nights, meal plan).
 *
 * A 3-day Nuratau tour usually has two stays: a night at the yurt camp
 * and a night at the village homestay. Each row is independently
 * dispatchable to its accommodation supplier.
 */
class InquiryStay extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_inquiry_id',
        'accommodation_id',
        'accommodation_rate_id',
        'room_type',
        'room_count',
        'cost_per_unit_usd',
        'total_accommodation_cost',
        'cost_override',
        'sort_order',
        'stay_date',
        'nights',
        'guest_count',
        'meal_plan',
        'notes',
    ];

    protected $casts = [
        'stay_date'                => 'date',
        'cost_per_unit_usd'        => 'decimal:2',
        'total_accommodation_cost' => 'decimal:2',
        'cost_override'            => 'boolean',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class, 'booking_inquiry_id');
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function accommodationRate(): BelongsTo
    {
        return $this->belongsTo(AccommodationRate::class);
    }

    /**
     * Auto-calculate accommodation cost from the linked rate.
     * Called when accommodation or guest_count changes.
     */
    public function calculateCost(): void
    {
        if ($this->cost_override) {
            return; // operator manually set it, don't overwrite
        }

        $accommodation = $this->accommodation;
        if (! $accommodation) {
            return;
        }

        $guests = $this->guest_count ?: 1;
        $nights = $this->nights ?: 1;

        $rate = $accommodation->costForGuests($guests);

        if ($rate) {
            $this->accommodation_rate_id = $rate->id;
            $this->cost_per_unit_usd     = $rate->cost_usd;
            $this->room_type             = $rate->room_type;
            $this->total_accommodation_cost = $rate->cost_usd * $guests * $nights;
        }
    }
}
