<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Employee extends Model
{
    use HasFactory;

    protected $casts = [
        'lang_spoken' => 'array',
    ];

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone01', 'phone02', 'lang_spoken', 'image',
        'extra_info',
        'school',
        'passport_image',
        'fathers_name',
        'pinfl',
        'address'
    ];

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(SpokenLanguage::class, 'language_employee');
    }
}
