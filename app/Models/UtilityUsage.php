<?php

namespace App\Models;

use App\Services\Meters\MeterReadingChainService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UtilityUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'utility_id',
        'meter_id',
        'hotel_id',
        'usage_date',
        'meter_latest',
        'meter_previous',
        'meter_difference',
        'meter_image',
        'is_meter_reset',
        'meter_previous_overridden',
        'meter_previous_override_reason',
    ];

    protected $casts = [
        'usage_date'                 => 'date',
        'is_meter_reset'             => 'boolean',
        'meter_previous_overridden'  => 'boolean',
    ];

    /**
     * Backend chain guard. The Filament form helps the operator, but
     * every write path (tinker, batch script, future API) goes through
     * the same MeterReadingChainService so the chain invariants hold
     * regardless of caller.
     *
     * The guard also recomputes meter_difference from meter_latest −
     * meter_previous, replacing the previous setMeterDifference
     * mutator so the source of truth lives in one place.
     */
    protected static function booted(): void
    {
        static::saving(function (self $reading) {
            // Default-fill on create when the form omitted previous.
            if ($reading->meter_id && $reading->meter_previous === null) {
                $reading->meter_previous = app(MeterReadingChainService::class)
                    ->autoFillPrevious((int) $reading->meter_id);
            }

            app(MeterReadingChainService::class)->validate($reading);

            $reading->meter_difference = app(MeterReadingChainService::class)->differenceFor($reading);
        });
    }

    public function meter()
    {
        return $this->belongsTo(Meter::class);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function utility()
    {
        return $this->belongsTo(Utility::class);
    }
}
