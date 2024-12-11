<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'expense_date',
        'amount',
        'hotel_id',
        'payment_type'
    ];

    protected $casts = [
        'amount' => MoneyCast::class,
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function category()
{
    return $this->belongsTo(ExpenseCategory::class);
}
}
