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

    public static function getNavigationLabel(): string
    {
        return __('cash.cash_drawers');
    }

    public static function getModelLabel(): string
    {
        return __('cash.cash_drawer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cash.cash_drawers');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Cash Management';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('cash.drawer_name'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('location')
                    ->label(__('cash.drawer_location'))
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->label(__('cash.active'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('cash.drawer_name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location')
                    ->label(__('cash.drawer_location'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label(__('cash.active')),
                Tables\Columns\TextColumn::make('active_currencies')
                    ->label(__('cash.active_currencies'))
                    ->getStateUsing(function ($record) {
                        // Get all shifts (open and closed) to find all currencies ever used
                        $allShifts = $record->shifts()->with(['transactions'])->get();

                        if ($allShifts->isEmpty()) {
                            return 'None';
                        }

                        $allCurrencies = collect();
                        foreach ($allShifts as $shift) {
                            // Get currencies from transactions
                            $transactionCurrencies = $shift->getUsedCurrencies();
                            
                            // Get currencies from beginning saldos
                            // Simple beginning saldo handling
                            $beginningSaldoCurrencies = $shift->beginning_saldo > 0 ? collect([Currency::UZS]) : collect();
                            
                            // Get currencies from end saldos (for closed shifts)
                            // Simplified - no endSaldos relationship
                            $endSaldoCurrencies = collect();
                            
                            // Also include UZS if there's a legacy beginning_saldo
                            $shiftCurrencies = $transactionCurrencies->merge($beginningSaldoCurrencies)->merge($endSaldoCurrencies);
                            if ($shift->beginning_saldo > 0) {
                                $shiftCurrencies = $shiftCurrencies->push(Currency::UZS);
                            }
                            
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
                    ->label(__('cash.current_balance'))
                    ->getStateUsing(function ($record) {
                        // Get all shifts to find the most recent state
                        $allShifts = $record->shifts()->with(['transactions'])->get();

                        if ($allShifts->isEmpty()) {
                            return 'No shifts yet';
                        }

                        // Get all currencies that have been used
                        $allCurrencies = collect();
                        foreach ($allShifts as $shift) {
                            $transactionCurrencies = $shift->getUsedCurrencies();
                            // Simple beginning saldo handling
                            $beginningSaldoCurrencies = $shift->beginning_saldo > 0 ? collect([Currency::UZS]) : collect();
                            // Simplified - no endSaldos relationship
                            $endSaldoCurrencies = collect();
                            
                            $shiftCurrencies = $transactionCurrencies->merge($beginningSaldoCurrencies)->merge($endSaldoCurrencies);
                            if ($shift->beginning_saldo > 0) {
                                $shiftCurrencies = $shiftCurrencies->push(Currency::UZS);
                            }
                            
                            $allCurrencies = $allCurrencies->merge($shiftCurrencies);
                        }
                        
                        $uniqueCurrencies = $allCurrencies->unique();
                        $balancesByCurrency = [];
                        
                        foreach ($uniqueCurrencies as $currency) {
                            // Find the most recent shift that has this currency
                            $mostRecentShift = null;
                            foreach ($allShifts->sortByDesc('created_at') as $shift) {
                                $hasCurrency = $shift->getUsedCurrencies()->contains($currency) ||
                                             ($shift->beginning_saldo > 0 && $currency === Currency::UZS) ||
                                             false || // endSaldos not used in simplified version
                                             ($currency === Currency::UZS && $shift->beginning_saldo > 0);
                                
                                if ($hasCurrency) {
                                    $mostRecentShift = $shift;
                                    break;
                                }
                            }
                            
                            if ($mostRecentShift) {
                                if ($mostRecentShift->status->value === 'open') {
                                    // For open shifts, use current balance
                                    $balance = $mostRecentShift->getNetBalanceForCurrency($currency);
                                } else {
                                    // For closed shifts, use the counted end saldo
                                    // Simplified - no endSaldos relationship
                                    $endSaldo = null;
                                    $balance = $endSaldo ? $endSaldo->counted_end_saldo : 0;
                                }
                                
                                if ($balance != 0) {
                                    $balancesByCurrency[] = $currency->formatAmount($balance);
                                }
                            }
                        }

                        if (empty($balancesByCurrency)) {
                            return 'No balances';
                        }

                        return implode(' | ', $balancesByCurrency);
                    })
                    ->html(),
                Tables\Columns\TextColumn::make('shifts_count')
                    ->counts('shifts')
                    ->label(__('cash.total_shifts')),
                Tables\Columns\TextColumn::make('open_shifts_count')
                    ->counts('openShifts')
                    ->label(__('cash.active_shifts')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('cash.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('cash.active')),
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