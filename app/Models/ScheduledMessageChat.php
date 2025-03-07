<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledMessageChat extends Model
{
    use HasFactory;

    // By default, Laravel expects a table named 'scheduled_message_chats',
    // but we named it 'scheduled_message_chat'. So we override $table:
    protected $table = 'scheduled_message_chat';

    protected $fillable = [
        'scheduled_message_id',
        'chat_id',
    ];

    public function scheduledMessage()
    {
        return $this->belongsTo(ScheduledMessage::class);
    }
}
