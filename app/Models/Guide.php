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
        'lang_spoken' => 'array',
        'is_active'   => 'boolean',
    ];

    protected $fillable = ['first_name', 'last_name', 'email', 'phone01', 'phone02', 'lang_spoken', 'guide_image', 'telegram_chat_id', 'is_active'];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
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
