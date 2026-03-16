<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'telegram_chat_id',
        'tour_ids',
    ];

    protected $casts = [
        'tour_ids' => 'array',
    ];

    public function managesTour(int $tourId): bool
    {
        return in_array($tourId, $this->tour_ids ?? []);
    }
}
