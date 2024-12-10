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
        'notes',
        'user_id',
        'image'
    ];

    public function acceptedBy()
{
    return $this->belongsTo(User::class, 'user_id');
}

// Automatically set `created_by` and `updated_by`
protected static function boot()
{
    parent::boot();

    static::creating(function ($model) {
        $model->created_by = auth()->id();
    });

    static::updating(function ($model) {
        $model->updated_by = auth()->id();
    });
}


}
