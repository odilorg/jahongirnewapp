<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingWebhook extends Model
{
    // Disable Laravel's default created_at/updated_at columns — this table
    // uses received_at / processed_at instead.
    public $timestamps = false;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED  = 'processed';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'source',
        'event_id',
        'payload',
        'status',
        'error',
        'attempts',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'received_at'  => 'datetime',
        'processed_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function markProcessing(): void
    {
        $this->update([
            'status'   => self::STATUS_PROCESSING,
            'attempts' => $this->attempts + 1,
        ]);
    }

    public function markProcessed(): void
    {
        $this->update([
            'status'       => self::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error'  => mb_substr($error, 0, 500),
        ]);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
