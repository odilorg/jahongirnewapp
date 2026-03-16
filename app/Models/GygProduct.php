<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GygProduct extends Model
{
    protected $table = 'gyg_products';

    protected $fillable = [
        'gyg_product_id',
        'internal_product_id',
        'name',
        'currency',
        'default_vacancies',
        'default_cutoff_seconds',
        'is_active',
        'price_categories',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_categories' => 'array',
        'default_vacancies' => 'integer',
        'default_cutoff_seconds' => 'integer',
    ];

    public function availabilities()
    {
        return $this->hasMany(GygAvailability::class, 'gyg_product_id', 'gyg_product_id');
    }
}
