<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        "guide_id",
        'sold_tour_id',
        'amount_paid',
        'payment_date',
        'payment_document_image',
        'payment_method'
    ];


    
}
