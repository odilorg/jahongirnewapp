<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Utility extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    public function utilityUsages(): HasMany
    {
        return $this->hasMany(UtilityUsage::class);
    }
}
