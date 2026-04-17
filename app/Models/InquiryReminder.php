<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryReminder extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_DONE      = 'done';
    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_PENDING   => 'Pending',
        self::STATUS_DONE      => 'Done',
        self::STATUS_DISMISSED => 'Dismissed',
    ];

    protected $fillable = [
        'booking_inquiry_id',
        'remind_at',
        'message',
        'created_by_user_id',
        'assigned_to_user_id',
        'status',
        'notified_at',
        'completed_at',
        'completed_by_user_id',
    ];

    protected $casts = [
        'remind_at'    => 'datetime',
        'notified_at'  => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopePending($q)
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeDue($q)
    {
        return $q->where('status', self::STATUS_PENDING)
            ->where('remind_at', '<=', now());
    }

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
