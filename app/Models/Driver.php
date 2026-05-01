<?php

namespace App\Models;

use App\Models\Car;
use App\Models\Rating;
use App\Models\CarDriver;
use App\Models\SupplierPayment;
use App\Models\TourRepeaterDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Driver extends Model
{
    use HasFactory;
    protected $fillable = ['address_city', 'extra_details', 'car_id', 'first_name', 'last_name', 'email', 'phone01', 'phone02', 'fuel_type', 'driver_image', 'telegram_chat_id', 'is_active', 'card_number', 'card_bank', 'card_holder_name', 'card_updated_at'];

    protected $casts = [
        'is_active'        => 'boolean',
        'card_updated_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        // Stamp card_updated_at when, and ONLY when, the card number itself
        // changes. Avoids touching the timestamp on unrelated profile edits.
        static::saving(function (Driver $driver) {
            if ($driver->isDirty('card_number')) {
                $driver->card_updated_at = $driver->card_number ? now() : null;
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Strip everything except digits before persisting. Operator may paste
     * "8600 1234 5678 9012" or "8600-1234-5678-9012"; we always store
     * "8600123456789012". Empty input → null (so card_updated_at stays null
     * for suppliers without a card).
     */
    public function setCardNumberAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['card_number'] = null;

            return;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        $this->attributes['card_number'] = $digits === '' ? null : $digits;
    }

    /**
     * "8600 1234 5678 9012" — for display only. Storage stays digits-only.
     */
    public function getCardNumberFormattedAttribute(): ?string
    {
        if (! $this->card_number) {
            return null;
        }

        return trim(chunk_split($this->card_number, 4, ' '));
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }


    // public function carsplates(): HasMany
    // {
    //     return $this->hasMany(CarDriver::class);
    // }
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

   public function averageScore(): ?float
{
    return $this->ratings()->avg('score');
}

public function totalRatings(): int
{
    return $this->ratings()->count();
}
    

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function tourExpenses()
    {
        return $this->morphMany(TourExpense::class, 'supplier');
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(TourFeedback::class);
    }

    /**
     * Rolling avg driver_rating over the trailing $days, only across
     * submitted feedbacks. Null if no submissions in window.
     */
    public function averageRating(int $days = 90): ?float
    {
        $avg = $this->feedbacks()
            ->submitted()
            ->whereNotNull('driver_rating')
            ->where('submitted_at', '>=', now()->subDays($days))
            ->avg('driver_rating');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Tally of issue tags from submitted feedbacks in the trailing window.
     * Returns ['punctuality' => 4, 'communication' => 2, …] sorted desc.
     */
    public function issueTagSummary(int $days = 90): array
    {
        $rows = $this->feedbacks()
            ->submitted()
            ->whereNotNull('driver_issue_tags')
            ->where('submitted_at', '>=', now()->subDays($days))
            ->pluck('driver_issue_tags');

        $tally = [];
        foreach ($rows as $tags) {
            foreach ((array) $tags as $tag) {
                $tally[$tag] = ($tally[$tag] ?? 0) + 1;
            }
        }
        arsort($tally);

        return $tally;
    }

    public function rates(): HasMany
    {
        return $this->hasMany(DriverRate::class)->orderBy('sort_order')->orderBy('label');
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'supplier_id')
            ->where('supplier_type', 'driver');
    }

    public function payments()
    {
        return SupplierPayment::forSupplier('driver', $this->id)->recorded();
    }

    public function totalOwed(): float
    {
        return (float) BookingInquiry::where('driver_id', $this->id)
            ->whereNotNull('driver_cost')
            ->sum('driver_cost');
    }

    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function outstandingBalance(): float
    {
        return $this->totalOwed() - $this->totalPaid();
    }
}
