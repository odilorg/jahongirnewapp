<?php

declare(strict_types=1);

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Sidebar cluster grouping all money-related views — guest / supplier
 * payments, cash-management (drawers, shifts, handovers, reconciliation),
 * reports, and balances.
 */
class Money extends Cluster
{
    protected static ?string $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Money';
    protected static ?int    $navigationSort  = 40;

    public static function canAccess(): bool
    {
        return true;
    }
}
