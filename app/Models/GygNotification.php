<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GygNotification extends Model
{
    protected $table = 'gyg_notifications';

    protected $fillable = [
        'notification_type',
        'description',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
