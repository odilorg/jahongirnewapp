<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadInterestFormat;
use App\Enums\LeadInterestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadInterest extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id', 'tour_product_id', 'tour_freeform',
        'requested_date', 'requested_date_flex',
        'pax_adults', 'pax_children',
        'format', 'direction_code',
        'pickup_city', 'dropoff_city',
        'dietary_requirements', 'special_requests', 'notes',
        'status',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'format'         => LeadInterestFormat::class,
        'status'         => LeadInterestStatus::class,
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
