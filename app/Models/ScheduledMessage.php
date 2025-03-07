<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ScheduledMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'message',
        'scheduled_at',
        'frequency',
        'status',
        'chat_id',
    ];

    /**
     * Frequencies we support.
     */
    public const FREQUENCY_NONE = 'none';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_YEARLY = 'yearly';

    /**
     * Relationship to the Chat model.
     * (Assuming each ScheduledMessage belongs to one Chat)
     */
    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * Reschedule the message based on frequency.
     * This will move the scheduled_at to the next interval
     * and reset the status to 'pending' so it's picked up again.
     */
    public function reschedule()
    {
        if ($this->frequency === self::FREQUENCY_NONE) {
            // Not recurring; do nothing.
            return;
        }

        $newDate = Carbon::parse($this->scheduled_at);

        switch ($this->frequency) {
            case self::FREQUENCY_DAILY:
                $newDate->addDay();
                break;
            case self::FREQUENCY_WEEKLY:
                $newDate->addWeek();
                break;
            case self::FREQUENCY_MONTHLY:
                $newDate->addMonth();
                break;
            case self::FREQUENCY_YEARLY:
                $newDate->addYear();
                break;
        }

        // Update scheduled_at and set status back to pending
        $this->update([
            'scheduled_at' => $newDate,
            'status'       => 'pending',
        ]);
    }
}
