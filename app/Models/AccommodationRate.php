<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccommodationRate extends Model
{
    public const TYPE_PER_PERSON = 'per_person';
    public const TYPE_PER_ROOM   = 'per_room';

    public const TYPES = [
        self::TYPE_PER_PERSON => 'Per person',
        self::TYPE_PER_ROOM   => 'Per room',
    ];

    protected $fillable = [
        'accommodation_id',
        'rate_type',
        'room_type',
        'label',
        'min_occupancy',
        'max_occupancy',
        'cost_usd',
        'includes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'cost_usd'      => 'decimal:2',
        'is_active'     => 'boolean',
        'min_occupancy' => 'integer',
        'max_occupancy' => 'integer',
        'sort_order'    => 'integer',
    ];

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }
}
