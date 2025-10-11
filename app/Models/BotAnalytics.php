<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class BotAnalytics extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'total_messages',
        'successful_queries',
        'failed_queries',
        'average_response_time',
        'unique_users',
    ];

    protected $casts = [
        'date' => 'date',
        'total_messages' => 'integer',
        'successful_queries' => 'integer',
        'failed_queries' => 'integer',
        'average_response_time' => 'decimal:2',
        'unique_users' => 'integer',
    ];

    public static function recordMessage(bool $success, float $responseTime, int $chatId)
    {
        $today = Carbon::today();
        
        $analytics = self::firstOrCreate(
            ['date' => $today],
            [
                'total_messages' => 0,
                'successful_queries' => 0,
                'failed_queries' => 0,
                'average_response_time' => 0,
                'unique_users' => 0,
            ]
        );

        $analytics->total_messages++;
        
        if ($success) {
            $analytics->successful_queries++;
        } else {
            $analytics->failed_queries++;
        }

        // Calculate new average response time
        $totalTime = ($analytics->average_response_time * ($analytics->total_messages - 1)) + $responseTime;
        $analytics->average_response_time = $totalTime / $analytics->total_messages;

        // Update unique users count
        $analytics->unique_users = \App\Models\TelegramBotConversation::whereDate('created_at', $today)
            ->distinct('chat_id')
            ->count('chat_id');

        $analytics->save();
    }

    public function getSuccessRateAttribute()
    {
        if ($this->total_messages === 0) {
            return 0;
        }
        
        return round(($this->successful_queries / $this->total_messages) * 100, 2);
    }
}
