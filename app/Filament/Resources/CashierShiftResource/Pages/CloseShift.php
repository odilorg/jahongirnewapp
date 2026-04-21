<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Actions\CloseShiftAction;
use App\Filament\Resources\CashierShiftResource;
use App\Enums\Currency;
use Filament\Actions;
use Filament\Forms;
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
        
        // Load relationships to ensure endSaldos are available
        $this->record = $this->record->load(['endSaldos', 'beginningSaldos', 'transactions', 'user', 'cashDrawer']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('closeShift')
                ->label('Close Shift')
                ->color('danger')
                ->icon('heroicon-o-stop')
                ->form([
                    Forms\Components\Repeater::make('counted_end_saldos')
                        ->label('Counted Balances')
                        ->default(fn () => $this->getDefaultCountedEndSaldos())
                        ->columns(1)
                        ->disableItemCreation()
                        ->disableItemDeletion()
                        ->schema([
                            Forms\Components\Select::make('currency')
                                ->label(__c('currency'))
                                ->options(fn () => $this->getCurrencyOptions())
                                ->required()
                                ->disabled()
                                ->dehydrated(),
                            Forms\Components\TextInput::make('counted_end_saldo')
                                ->label(__c('counted_balance'))
                                ->numeric()
                                ->required()
                                ->minValue(0),
                            Forms\Components\Repeater::make('denominations')
                                ->label('Denominations (optional)')
                                ->schema([
                                    Forms\Components\TextInput::make('denomination')
                                        ->label('Denomination')
                                        ->numeric()
                                        ->minValue(0.01)
                                        ->required(),
                                    Forms\Components\TextInput::make('qty')
                                        ->label('Quantity')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(0)
                                        ->required(),
                                ])
                                ->columns(2)
                                ->collapsed()
                                ->defaultItems(0),
                        ])
                        ->required(),
                    Forms\Components\Textarea::make('notes')
                        ->label(__c('notes'))
                        ->maxLength(1000),
                    Forms\Components\Textarea::make('discrepancy_reason')
                        ->label(__c('discrepancy_reason'))
                        ->maxLength(1000),
                ])
                ->action(function (array $data) {
                    try {
                        $this->record = $this->record->fresh(['endSaldos', 'beginningSaldos', 'transactions']);

                        $counted = collect($data['counted_end_saldos'] ?? [])
                            ->map(function (array $entry) {
                                $entry['counted_end_saldo'] = (float) ($entry['counted_end_saldo'] ?? 0);

                                if (!empty($entry['denominations'])) {
                                    $entry['denominations'] = collect($entry['denominations'])
                                        ->filter(fn ($row) => isset($row['denomination'], $row['qty']) && $row['denomination'] !== null && $row['qty'] !== null)
                                        ->map(function ($row) {
                                            return [
                                                'denomination' => (float) $row['denomination'],
                                                'qty' => (int) $row['qty'],
                                            ];
                                        })
                                        ->values()
                                        ->all();
                                } else {
                                    $entry['denominations'] = [];
                                }

                                return $entry;
                            })
                            ->values()
                            ->all();

                        $payload = [
                            'counted_end_saldos' => $counted,
                            'notes' => $data['notes'] ?? null,
                            'discrepancy_reason' => $data['discrepancy_reason'] ?? null,
                        ];

                        $shift = app(CloseShiftAction::class)->execute($this->record, auth()->user(), $payload);

                        $endSaldosCount = $shift->fresh()->endSaldos->count();

                        Notification::make()
                            ->title('Shift Closed Successfully')
                            ->body("Shift #{$shift->id} has been closed with {$endSaldosCount} end saldo records")
                            ->success()
                            ->send();

                        return redirect()->route('filament.admin.resources.cashier-shifts.index');
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error Closing Shift')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }


    protected function getDefaultCountedEndSaldos(): array
    {
        return collect($this->getShiftCurrencies())
            ->map(fn (Currency $currency) => [
                'currency' => $currency->value,
                'counted_end_saldo' => $this->record->getNetBalanceForCurrency($currency),
                'denominations' => [],
            ])
            ->values()
            ->all();
    }

    protected function getCurrencyOptions(): array
    {
        return collect($this->getShiftCurrencies())
            ->mapWithKeys(fn (Currency $currency) => [$currency->value => $currency->getLabel()])
            ->all();
    }

    protected function getShiftCurrencies(): array
    {
        $currencies = collect($this->record->getUsedCurrencies());
        $beginning = $this->record->beginningSaldos->pluck('currency');

        if ($this->record->beginning_saldo > 0) {
            $currencies = $currencies->push(Currency::UZS);
        }

        $merged = $currencies
            ->merge($beginning)
            ->filter()
            ->map(fn ($currency) => $currency instanceof Currency ? $currency : Currency::from($currency))
            ->unique()
            ->values();

        if ($merged->isEmpty()) {
            return [Currency::UZS];
        }

        return $merged->all();
    }

}