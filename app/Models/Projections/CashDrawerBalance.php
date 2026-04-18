<?php

declare(strict_types=1);

namespace App\Models\Projections;

use App\Models\CashDrawer;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L-005 projection — per (cash_drawer_id, currency) running balance.
 *
 * Derived from ledger_entries by App\Listeners\Ledger\UpdateBalanceProjections.
 * Rebuildable from scratch via `php artisan ledger:rebuild-projections`.
 * Do NOT write to this model from business code — ledger first, then
 * the projection is maintained automatically.
 *
 * CREATED_AT is intentionally absent: projections are upserts, not events.
 */
class CashDrawerBalance extends Model
{
    protected $table = 'cash_drawer_balances';

    public const CREATED_AT = null;

    protected $fillable = [
        'cash_drawer_id',
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

    public function cashDrawer(): BelongsTo
    {
        return $this->belongsTo(CashDrawer::class, 'cash_drawer_id');
    }

    public function lastEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'last_entry_id');
    }
}
