<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Actions\CloseShiftAction;
use App\Filament\Resources\CashierShiftResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;

class CloseShift extends ViewRecord
{
    protected static string $resource = CashierShiftResource::class;

    protected static string $view = 'filament.resources.cashier-shift-resource.pages.close-shift';

    protected static ?string $title = 'Close Shift';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('closeShift')
                ->label('Close Shift')
                ->color('danger')
                ->icon('heroicon-o-stop')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        $data = [
                            'counted_end_saldo' => $this->record->calculateExpectedEndSaldo(),
                            'denominations' => [
                                ['denomination' => 100, 'qty' => 1],
                            ],
                            'notes' => 'Closed via simplified interface',
                        ];
                        
                        $shift = app(CloseShiftAction::class)->execute($this->record, auth()->user(), $data);
                        
                        Notification::make()
                            ->title('Shift Closed Successfully')
                            ->body("Shift #{$shift->id} has been closed")
                            ->success()
                            ->send();
                            
                        return redirect()->route('filament.admin.resources.cashier-shifts.index');
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Error Closing Shift')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}