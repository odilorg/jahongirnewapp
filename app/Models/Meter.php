<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meter extends Model
{
    use HasFactory;

    protected $fillable = [
        'meter_serial_number',
        'utility_id',
        'sertificate_expiration_date',
        'sertificate_image',
        'contract_number',
        'contract_date',
        'hotel_id'
    ];

    public function utility()
    {
        return $this->belongsTo(Utility::class);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function utilityUsages()
    {
        return $this->hasMany(UtilityUsage::class);
    }
}
