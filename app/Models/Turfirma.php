<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Turfirma extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'official_name',
        'address_street',
        'address_city',
        'phone',
        'email',
        'inn',
        'account_number',
        'bank_name',
        'bank_mfo',
        'director_name',
        'type',
        'files'
    ];

   

    
}
