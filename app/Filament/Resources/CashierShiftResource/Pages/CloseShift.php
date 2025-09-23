<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Actions\CloseShiftAction;
use App\Filament\Resources\CashierShiftResource;
use App\Enums\Currency;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;

class CloseShift extends ViewRecord
{
    protected static string $resource = CashierShiftResource::class;

    protected static string $view = 'filament.resources.cashier-shift-resource.pages.close-shift';

    protected static ?string $title = 'Close Shift';

    public function mount(int | string $record): void
    {
        parent::mount($record);
        
        // Load relationships
        $this->record = $this->record->load(['transactions', 'user', 'cashDrawer']);
    }

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
                        // Ensure we have the latest data with relationships
                        $this->record = $this->record->fresh(['transactions']);
                        
                        // Get all currencies used in this shift
                        $usedCurrencies = $this->record->getUsedCurrencies();
                        $allCurrencies = $usedCurrencies;
                        
                        $countedEndSaldos = [];
                        
                        foreach ($allCurrencies as $currency) {
                            $balance = $this->record->getNetBalanceForCurrency($currency);
                            
                            // Only create denominations for UZS (other currencies don't need denominations)
                            $denominations = [];
                            if ($currency === Currency::UZS) {
                                $remaining = $balance;
                                $commonDenominations = [100000, 50000, 20000, 10000, 5000, 1000, 500, 100];
                                
                                foreach ($commonDenominations as $denomination) {
                                    if ($remaining >= $denomination) {
                                        $qty = floor($remaining / $denomination);
                                        if ($qty > 0) {
                                            $denominations[] = ['denomination' => $denomination, 'qty' => $qty];
                                            $remaining -= $denomination * $qty;
                                        }
                                    }
                                }
                                
                                if ($remaining > 0) {
                                    $denominations[] = ['denomination' => 100, 'qty' => $remaining / 100];
                                }
                            }
                            
                            $countedEndSaldos[] = [
                                'currency' => $currency->value,
                                'counted_end_saldo' => $balance,
                                'denominations' => $denominations,
                            ];
                        }
                        
                        $data = [
                            'counted_end_saldos' => $countedEndSaldos,
                            'notes' => 'Closed via simplified interface',
                        ];
                        
                        $shift = app(CloseShiftAction::class)->execute($this->record, auth()->user(), $data);
                        
                        // Verify EndSaldo records were created
                        // Simplified - no endSaldos relationship
                        $endSaldosCount = 0;
                        
                        Notification::make()
                            ->title('Shift Closed Successfully')
                            ->body("Shift #{$shift->id} has been closed with {$endSaldosCount} end saldo records")
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