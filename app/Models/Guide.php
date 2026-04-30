<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Guide extends Model
{
    use HasFactory;

    protected $casts = [
        'lang_spoken'      => 'array',
        'is_active'        => 'boolean',
        'card_updated_at'  => 'datetime',
    ];

    protected $fillable = ['first_name', 'last_name', 'email', 'phone01', 'phone02', 'lang_spoken', 'guide_image', 'telegram_chat_id', 'is_active', 'card_number', 'card_bank', 'card_holder_name', 'card_updated_at'];

    protected static function booted(): void
    {
        // Stamp card_updated_at when, and ONLY when, the card number itself
        // changes. Avoids touching the timestamp on unrelated profile edits.
        static::saving(function (Guide $guide) {
            if ($guide->isDirty('card_number')) {
                $guide->card_updated_at = $guide->card_number ? now() : null;
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
     * "8600123456789012".
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

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(SpokenLanguage::class, 'language_guide');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function tourExpenses()
    {
        return $this->morphMany(TourExpense::class, 'supplier');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(GuideRate::class)->orderBy('sort_order')->orderBy('label');
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'supplier_id')
            ->where('supplier_type', 'guide');
    }

    public function payments()
    {
        return SupplierPayment::forSupplier('guide', $this->id)->recorded();
    }

    public function totalOwed(): float
    {
        return (float) BookingInquiry::where('guide_id', $this->id)
            ->whereNotNull('guide_cost')
            ->sum('guide_cost');
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
