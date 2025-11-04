<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GetYourGuideBooking extends Model
{
    protected $table = 'getyourguide_bookings';

    protected $fillable = [
        'email_message_id',
        'email_subject',
        'email_date',
        'raw_email_body',
        'raw_email_html',
        'tour_id',
        'booking_reference',
        'booking_date',
        'tour_name',
        'tour_date',
        'tour_time',
        'duration',
        'guest_name',
        'guest_email',
        'guest_phone',
        'number_of_guests',
        'adults',
        'children',
        'pickup_location',
        'pickup_time',
        'special_requirements',
        'total_price',
        'currency',
        'payment_status',
        'processing_status',
        'ai_extraction_attempts',
        'ai_response',
        'error_message',
        'processed_at',
        'notified_at',
    ];

    protected $casts = [
        'email_date' => 'datetime',
        'booking_date' => 'date',
        'tour_date' => 'date',
        'ai_response' => 'array',
        'processed_at' => 'datetime',
        'notified_at' => 'datetime',
        'ai_extraction_attempts' => 'integer',
        'number_of_guests' => 'integer',
        'adults' => 'integer',
        'children' => 'integer',
        'total_price' => 'decimal:2',
    ];

    // Relationships
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('processing_status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('processing_status', 'processing');
    }

    public function scopeFailed($query)
    {
        return $query->where('processing_status', 'failed');
    }

    public function scopeCompleted($query)
    {
        return $query->where('processing_status', 'completed');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('tour_date', '>=', now()->toDateString());
    }

    public function scopeUnnotified($query)
    {
        return $query->whereNull('notified_at');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('tour_date', today());
    }

    // Accessors
    public function getFormattedPriceAttribute(): ?string
    {
        return $this->total_price
            ? number_format($this->total_price, 2) . ' ' . $this->currency
            : null;
    }

    public function getGuestCountAttribute(): int
    {
        return ($this->adults ?? 0) + ($this->children ?? 0);
    }

    // Methods
    public function markAsProcessed(): void
    {
        $this->update([
            'processing_status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'processing_status' => 'failed',
            'error_message' => $error,
            'ai_extraction_attempts' => $this->ai_extraction_attempts + 1,
        ]);
    }

    public function incrementAttempts(): void
    {
        $this->increment('ai_extraction_attempts');
    }
}
