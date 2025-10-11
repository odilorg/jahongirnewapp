<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TelegramBotConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'message_id',
        'user_id',
        'username',
        'message_text',
        'check_in_date',
        'check_out_date',
        'ai_response',
        'availability_data',
        'status',
        'error_message',
        'response_time',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'message_id' => 'integer',
        'user_id' => 'integer',
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'ai_response' => 'array',
        'availability_data' => 'array',
        'response_time' => 'decimal:2',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeByUser($query, $chatId)
    {
        return $query->where('chat_id', $chatId);
    }
}
