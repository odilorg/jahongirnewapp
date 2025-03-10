<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramConversation extends Model
{
    protected $fillable = [
        'chat_id',
        'step',
        'data',
        'updated_at',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public $timestamps = false; // If you only have updated_at
}
