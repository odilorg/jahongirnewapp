<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'message',
        'scheduled_at',
        'status',
        'frequency',
        // removed 'chat_id' since we use the pivot table now
    ];

    /**
     * Relationship to pivot model scheduled_message_chat.
     * One ScheduledMessage has many "chat" rows in the pivot table.
     */
    public function chats()
    {
        return $this->belongsToMany(Chat::class, 'scheduled_message_chat');
    }
}
