<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StaffBookingRequest extends Model
{
    protected $fillable = [
        'staff_id',
        'chat_id',
        'message_id',
        'request_type',
        'raw_message',
        'parsed_intent',
        'check_in_date',
        'check_out_date',
        'property_id',
        'property_name',
        'room_id',
        'room_name',
        'unit_name',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_notes',
        'total_price',
        'currency',
        'beds24_booking_id',
        'beds24_request',
        'beds24_response',
        'status',
        'error_message',
        'response_time',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'message_id' => 'integer',
        'parsed_intent' => 'array',
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'total_price' => 'decimal:2',
        'beds24_request' => 'array',
        'beds24_response' => 'array',
        'response_time' => 'decimal:2',
    ];

    // Relationships
    public function staff(): BelongsTo
    {
        return $this->belongsTo(AuthorizedStaff::class);
    }

    public function managedBooking(): HasOne
    {
        return $this->hasOne(BotManagedBooking::class, 'staff_request_id');
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeByStaff($query, int $staffId)
    {
        return $query->where('staff_id', $staffId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('request_type', $type);
    }
}
