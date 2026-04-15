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
        'sort_order',
        'stay_date',
        'nights',
        'guest_count',
        'meal_plan',
        'notes',
    ];

    protected $casts = [
        'stay_date' => 'date',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class, 'booking_inquiry_id');
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }
}
