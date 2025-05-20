<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;
    protected $fillable = ['name', 'type', 'key'];
    public function ratings()
    {
        return $this->belongsToMany(Rating::class);
    }
}


