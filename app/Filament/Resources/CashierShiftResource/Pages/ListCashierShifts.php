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
            Actions\Action::make('startShift')
                ->label('Start Shift')
                ->icon('heroicon-o-play')
                ->color('success')
                ->size('lg')
                ->url(route('filament.admin.resources.cashier-shifts.start-shift'))
                ->visible(fn () => !auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),

            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
        ];
    }
}
