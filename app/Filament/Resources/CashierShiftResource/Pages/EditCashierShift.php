<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Filament\Resources\CashierShiftResource;
use App\Models\BeginningSaldo;
use App\Enums\Currency;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCashierShift extends EditRecord
{
    protected static string $resource = CashierShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Load existing beginning saldos into form data
        $beginningSaldos = $this->record->beginningSaldos;
        
        foreach ($beginningSaldos as $saldo) {
            $data["beginning_saldo_{$saldo->currency->value}"] = $saldo->amount;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Update beginning saldos for each currency
        $currencies = [
            'uzs' => Currency::UZS,
            'usd' => Currency::USD,
            'eur' => Currency::EUR,
            'rub' => Currency::RUB,
        ];

        foreach ($currencies as $key => $currency) {
            $amount = $this->data["beginning_saldo_{$key}"] ?? 0;
            
            if ($amount > 0) {
                BeginningSaldo::updateOrCreate(
                    [
                        'cashier_shift_id' => $this->record->id,
                        'currency' => $currency,
                    ],
                    [
                        'amount' => $amount,
                    ]
                );
            } else {
                // Delete if amount is 0 or not set
                BeginningSaldo::where('cashier_shift_id', $this->record->id)
                    ->where('currency', $currency)
                    ->delete();
            }
        }
    }
}
