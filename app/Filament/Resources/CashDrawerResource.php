<?php

namespace App\Filament\Resources;

use App\Enums\Currency;
use App\Filament\Resources\CashDrawerResource\Pages;
use App\Models\CashDrawer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CashDrawerResource extends Resource
{
    protected static ?string $model = CashDrawer::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Cash Management';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = true;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('location')
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->label('Active'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                Tables\Columns\TextColumn::make('active_currencies')
                    ->label('Active Currencies')
                    ->getStateUsing(function ($record) {
                        $openShifts = $record->openShifts()->with('beginningSaldos')->get();

                        if ($openShifts->isEmpty()) {
                            return 'None';
                        }

                        $allCurrencies = collect();
                        foreach ($openShifts as $shift) {
                            // Get currencies from both transactions AND beginning saldos
                            $transactionCurrencies = $shift->getUsedCurrencies();
                            $beginningSaldoCurrencies = $shift->beginningSaldos->pluck('currency');
                            
                            // Also include UZS if there's a legacy beginning_saldo
                            $shiftCurrencies = $transactionCurrencies->merge($beginningSaldoCurrencies);
                            if ($shift->beginning_saldo > 0) {
                                $shiftCurrencies = $shiftCurrencies->push(Currency::UZS);
                            }
                            
                            // Combine both sets of currencies
                            $allCurrencies = $allCurrencies->merge($shiftCurrencies);
                        }

                        $uniqueCurrencies = $allCurrencies->unique();
                        if ($uniqueCurrencies->isEmpty()) {
                            return 'No transactions yet';
                        }

                        return $uniqueCurrencies->map(fn($currency) => $currency->value)->join(', ');
                    })
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('multi_currency_balance')
                    ->label('Current Balances')
                    ->getStateUsing(function ($record) {
                        $openShifts = $record->openShifts()->with(['transactions', 'beginningSaldos'])->get();

                        if ($openShifts->isEmpty()) {
                            return 'No open shifts';
                        }

                        $balancesByCurrency = [];
                        foreach ($openShifts as $shift) {
                            // Get currencies from both transactions AND beginning saldos
                            $transactionCurrencies = $shift->getUsedCurrencies();
                            $beginningSaldoCurrencies = $shift->beginningSaldos->pluck('currency');
                            
                            // Also include UZS if there's a legacy beginning_saldo
                            $allCurrencies = $transactionCurrencies->merge($beginningSaldoCurrencies);
                            if ($shift->beginning_saldo > 0) {
                                $allCurrencies = $allCurrencies->push(Currency::UZS);
                            }
                            $allCurrencies = $allCurrencies->unique();
                            
                            foreach ($allCurrencies as $currency) {
                                $netBalance = $shift->getNetBalanceForCurrency($currency);
                                
                                if (!isset($balancesByCurrency[$currency->value])) {
                                    $balancesByCurrency[$currency->value] = [
                                        'currency' => $currency,
                                        'balance' => 0,
                                    ];
                                }
                                $balancesByCurrency[$currency->value]['balance'] += $netBalance;
                            }
                        }

                        if (empty($balancesByCurrency)) {
                            return 'No transactions yet';
                        }

                        $balanceStrings = [];
                        foreach ($balancesByCurrency as $data) {
                            $balanceStrings[] = $data['currency']->formatAmount($data['balance']);
                        }

                        return implode(', ', $balanceStrings);
                    })
                    ->html(),
                Tables\Columns\TextColumn::make('shifts_count')
                    ->counts('shifts')
                    ->label('Total Shifts'),
                Tables\Columns\TextColumn::make('open_shifts_count')
                    ->counts('openShifts')
                    ->label('Open Shifts'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashDrawers::route('/'),
            'create' => Pages\CreateCashDrawer::route('/create'),
            'edit' => Pages\EditCashDrawer::route('/{record}/edit'),
        ];
    }
}