<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Finer-grained taxonomy for incoming cash transactions.
 *
 * Sits underneath cash_transactions.category — the broad bucket
 * ('sale' / 'cash_in' / 'exchange') stays as-is for backwards
 * compatibility, while income_category_id captures the specific
 * petty-sale type (water, snacks, souvenirs, etc).
 *
 * Read-mostly table. Edits should be rare — operators see this
 * taxonomy in dropdowns; renaming a row affects display only,
 * not historical data.
 */
class IncomeCategory extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function cashTransactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }
}
