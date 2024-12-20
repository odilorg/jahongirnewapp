<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UtilityUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'utility_id',
        'meter_id',
        'hotel_id',
        'usage_date',
        'meter_latest',
        'meter_previous',
        'meter_difference',
        'meter_image'
    ];

    public function setMeterDifferenceAttribute($value)
    {
        $this->attributes['meter_difference'] = $this->meter_latest - $this->meter_previous;
    }

    public function meter()
    {
        return $this->belongsTo(Meter::class);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function utility()
    {
        return $this->belongsTo(Utility::class);
    }
}
