<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GygAvailability extends Model
{
    protected $table = 'gyg_availabilities';

    protected $fillable = [
        'gyg_product_id',
        'slot_datetime',
        'vacancies',
        'cutoff_seconds',
        'currency',
        'prices_by_category',
        'opening_times',
    ];

    protected $casts = [
        'slot_datetime' => 'datetime',
        'prices_by_category' => 'array',
        'opening_times' => 'array',
        'vacancies' => 'integer',
        'cutoff_seconds' => 'integer',
    ];
}
