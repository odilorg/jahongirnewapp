<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CarDriver extends Pivot
{
    use HasFactory;

    public function car()
{
    return $this->belongsTo(Car::class);
}

public function driver()
{
    return $this->belongsTo(Driver::class);
}
}
