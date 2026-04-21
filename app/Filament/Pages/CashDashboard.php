<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class CashDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 0;
    protected static ?string $navigationLabel = 'Cash Dashboard';
    protected static ?string $title = 'Cash Dashboard';
    protected static string $view = 'filament.pages.cash-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\CashTodayStats::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Widgets\CashFlowChart::class,
        ];
    }
}
