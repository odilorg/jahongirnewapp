<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Filament\Resources\CashierShiftResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCashierShifts extends ListRecords
{
    protected static string $resource = CashierShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Quick Start Shift - Available to everyone (but not if they already have an open shift)
            Actions\Action::make('startShift')
                ->label('Quick Start Shift')
                ->icon('heroicon-o-play')
                ->color('success')
                ->size('lg')
                ->url(route('filament.admin.resources.cashier-shifts.start-shift'))
                ->visible(fn () => !auth()->user()->hasOpenShifts()),

            // Detailed Create Form - Available to managers/admins for advanced setup
            Actions\CreateAction::make()
                ->label('Create Shift (Advanced)')
                ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
        ];
    }
}
