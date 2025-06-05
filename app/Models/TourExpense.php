<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TourExpense extends Model
{
    use HasFactory;

    protected $fillable = ['booking_id', 'tour_id', 'supplier_id', 'supplier_type', 'description', 'amount', 'expense_date'];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }

    public function supplier()
    {
        return $this->morphTo();
    }
    public function booking()
{
    return $this->belongsTo(Booking::class);
}

}

