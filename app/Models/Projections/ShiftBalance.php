<?php

declare(strict_types=1);

namespace App\Models\Projections;

use App\Models\CashierShift;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L-005 projection — per (cashier_shift_id, currency) running balance.
 *
 * Same lifecycle as CashDrawerBalance: derived, rebuildable, maintained
 * by App\Listeners\Ledger\UpdateBalanceProjections.
 */
class ShiftBalance extends Model
{
    protected $table = 'shift_balances';

    public const CREATED_AT = null;

    protected $fillable = [
        'cashier_shift_id',
        'currency',
        'balance',
        'total_in',
        'total_out',
        'in_count',
        'out_count',
        'last_entry_id',
        'last_entry_at',
    ];

    protected $casts = [
        'balance'       => 'decimal:2',
        'total_in'      => 'decimal:2',
        'total_out'     => 'decimal:2',
        'in_count'      => 'integer',
        'out_count'     => 'integer',
        'last_entry_at' => 'datetime',
    ];

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function lastEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'last_entry_id');
    }
}
