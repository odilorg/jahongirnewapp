<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\GroupMatchingEngine;
use Filament\Pages\Page;

class GroupMatches extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Group Matches';
    protected static ?string $navigationGroup = 'Tour Operations';
    protected static ?int    $navigationSort  = 30;

    protected static string $view = 'filament.pages.group-matches';

    public function getTitle(): string
    {
        return '🎯 Group Match Opportunities';
    }

    protected function getViewData(): array
    {
        $clusters = app(GroupMatchingEngine::class)->findClusters();

        $totalPotential    = $clusters->sum('estimated_revenue');
        $totalCurrent      = $clusters->sum('current_revenue');
        $totalPotentialUplift = $totalPotential - $totalCurrent;

        return [
            'clusters'              => $clusters,
            'count'                 => $clusters->count(),
            'total_potential'       => $totalPotential,
            'total_current'         => $totalCurrent,
            'total_potential_uplift' => $totalPotentialUplift,
        ];
    }
}
