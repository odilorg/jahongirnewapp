<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Chat extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',     // Human-readable name
        'chat_id',  // The actual Telegram ID
    ];

    public function scheduledMessages()
    {
        return $this->belongsToMany(ScheduledMessage::class, 'scheduled_message_chat');
    }
}
