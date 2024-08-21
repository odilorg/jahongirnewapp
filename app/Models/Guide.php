<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Guide extends Model
{
    use HasFactory;

    protected $casts = [
        'lang_spoken' => 'array',
    ];

    protected $fillable = ['first_name', 'last_name', 'email', 'phone01', 'phone02', 'lang_spoken', 'guide_image'];
}
