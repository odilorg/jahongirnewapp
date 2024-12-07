<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Zayavka extends Model
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
        
        'turfirma_id',
        'submitted_date',
        'status',
        'source',
        'accepted_by',
        'description',
        'hotel_id',
        'name',
        'rooming',
        'notes'
    ];
}
