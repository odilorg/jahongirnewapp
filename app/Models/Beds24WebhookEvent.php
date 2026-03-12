<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Beds24WebhookEvent extends Model
{
    protected $fillable = [
        'event_hash',
        'booking_id',
        'payload',
        'status',
        'attempts',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing', 'attempts' => $this->attempts + 1]);
    }

    public function markProcessed(): void
    {
        $this->update(['status' => 'processed', 'processed_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error]);
    }
}
