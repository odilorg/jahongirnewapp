<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Guest extends Model
{
    use HasFactory;
    protected $fillable = [
        'first_name',
         'last_name', 
         'email', 
         'phone', 
         'country',
         'amount_paid',
         'payment_date',
         'payment_document_image',
         'payment_method'
        ];

   
}
