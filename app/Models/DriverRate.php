<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverRate extends Model
{
    public const TYPE_PER_TRIP = 'per_trip';
    public const TYPE_PER_DAY  = 'per_day';

    public const TYPES = [
        self::TYPE_PER_TRIP => 'Per trip (flat)',
        self::TYPE_PER_DAY  => 'Per day',
    ];

    protected $fillable = [
        'driver_id',
        'label',
        'rate_type',
        'cost_usd',
        'notes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'cost_usd'   => 'decimal:2',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
