<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Accommodation;
use App\Models\Driver;
use App\Models\Guide;
use Filament\Pages\Page;

/**
 * Supplier Balances — one view showing outstanding amounts across
 * all drivers, guides, and accommodations. The "who do I owe" page.
 */
class SupplierBalances extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-scale';
    protected static ?string $navigationLabel = 'Supplier Balances';
    protected static ?string $navigationGroup = 'Tour Operations';
    protected static ?int    $navigationSort  = 20;

    protected static string $view = 'filament.pages.supplier-balances';

    public function getTitle(): string
    {
        return 'Supplier Balances';
    }

    protected function getViewData(): array
    {
        $suppliers = collect();

        // Drivers
        foreach (Driver::where('is_active', true)->orderBy('first_name')->get() as $d) {
            $owed = $d->totalOwed();
            $paid = $d->totalPaid();
            if ($owed == 0 && $paid == 0) {
                continue;
            }
            $suppliers->push([
                'type'        => 'Driver',
                'name'        => $d->full_name,
                'owed'        => $owed,
                'paid'        => $paid,
                'balance'     => $owed - $paid,
                'edit_url'    => \App\Filament\Resources\DriverResource::getUrl('edit', ['record' => $d->id]),
            ]);
        }

        // Guides
        foreach (Guide::where('is_active', true)->orderBy('first_name')->get() as $g) {
            $owed = $g->totalOwed();
            $paid = $g->totalPaid();
            if ($owed == 0 && $paid == 0) {
                continue;
            }
            $suppliers->push([
                'type'        => 'Guide',
                'name'        => $g->full_name,
                'owed'        => $owed,
                'paid'        => $paid,
                'balance'     => $owed - $paid,
                'edit_url'    => \App\Filament\Resources\GuideResource::getUrl('edit', ['record' => $g->id]),
            ]);
        }

        // Accommodations
        foreach (Accommodation::where('is_active', true)->orderBy('name')->get() as $a) {
            $owed = $a->totalOwed();
            $paid = $a->totalPaid();
            if ($owed == 0 && $paid == 0) {
                continue;
            }
            $suppliers->push([
                'type'        => 'Accommodation',
                'name'        => $a->name,
                'owed'        => $owed,
                'paid'        => $paid,
                'balance'     => $owed - $paid,
                'edit_url'    => \App\Filament\Resources\AccommodationResource::getUrl('edit', ['record' => $a->id]),
            ]);
        }

        // Sort by outstanding balance descending
        $sorted = $suppliers->sortByDesc('balance')->values();

        return [
            'suppliers'      => $sorted,
            'totalOwed'      => $sorted->sum('owed'),
            'totalPaid'      => $sorted->sum('paid'),
            'totalBalance'   => $sorted->sum('balance'),
        ];
    }
}
