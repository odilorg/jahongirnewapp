<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = ['booking_id', 'driver_id', 'guide_id', 'review_source', 'review_score', 'comments' ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    } 

     public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    } 

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    } 

       public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
   
}
