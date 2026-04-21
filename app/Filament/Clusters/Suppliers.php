<?php

declare(strict_types=1);

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

/**
 * Sidebar cluster grouping supplier-management resources (drivers,
 * guides, accommodations, cars, rates). Clicking this sidebar entry
 * opens a sub-page with horizontal tabs per resource — see
 * docs/architecture/LAYER_CHEAT_SHEET.md for placement rules.
 */
class Suppliers extends Cluster
{
    protected static ?string $navigationIcon          = 'heroicon-o-users';
    protected static ?string $navigationLabel         = 'Suppliers';
    protected static ?int    $navigationSort          = 30;
    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    // Visible to any panel user; inner resources retain their own
    // Shield permissions. Override prevents Shield's cluster_* gate
    // from hiding the whole sidebar entry.
    public static function canAccess(): bool
    {
        return true;
    }
}
