<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailProcessingLog extends Model
{
    const UPDATED_AT = null; // Only created_at

    protected $table = 'email_processing_log';

    protected $fillable = [
        'email_message_id',
        'email_from',
        'email_subject',
        'email_date',
        'action',
        'status',
        'details',
        'processing_time_ms',
    ];

    protected $casts = [
        'email_date' => 'datetime',
        'details' => 'array',
        'processing_time_ms' => 'integer',
    ];

    // Scopes
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeErrors($query)
    {
        return $query->where('status', 'error');
    }

    public function scopeSuccesses($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}
