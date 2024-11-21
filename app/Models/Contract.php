<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Contract extends Model
{
    use HasFactory;
    public function turfirma(): BelongsTo
    {
        return $this->belongsTo(Turfirma::class);
    }


    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    protected $fillable = [
        'date',
        'number',
        'hotel_id',
        'turfirma_id'
    ];
}
