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
                Forms\Components\Section::make('Drawer Details')
                    ->schema([
                        Forms\Components\Select::make('location_id')
                            ->relationship('location', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('location')
                            ->label('Physical Location (Legacy)')
                            ->maxLength(255)
                            ->helperText('Optional: e.g., "Near entrance", "Counter #3"'),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->label('Active')
                            ->helperText('Inactive drawers cannot be used for shifts'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('location')
                    ->label('Physical Location')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                Tables\Columns\TextColumn::make('active_currencies')
                    ->label('Used Currencies')
                    ->getStateUsing(function ($record) {
                        // Get all shifts (open and closed) to find all currencies ever used
                        $allShifts = $record->shifts()->with(['transactions', 'beginningSaldos', 'endSaldos'])->get();

                        if ($allShifts->isEmpty()) {
                            return 'None';
                        }

                        $allCurrencies = collect();
                        foreach ($allShifts as $shift) {
                            // Get currencies from transactions
                            $transactionCurrencies = $shift->getUsedCurrencies();
                            
                            // Get currencies from beginning saldos
                            $beginningSaldoCurrencies = $shift->beginningSaldos->pluck('currency');
                            
                            // Get currencies from end saldos (for closed shifts)
                            $endSaldoCurrencies = $shift->endSaldos->pluck('currency');
                            
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
                    ->label('Current Balances')
                    ->getStateUsing(function ($record) {
                        // Get all shifts to find the most recent state
                        $allShifts = $record->shifts()->with(['transactions', 'beginningSaldos', 'endSaldos'])->get();

                        if ($allShifts->isEmpty()) {
                            return 'No shifts yet';
                        }

                        // Get all currencies that have been used
                        $allCurrencies = collect();
                        foreach ($allShifts as $shift) {
                            $transactionCurrencies = $shift->getUsedCurrencies();
                            $beginningSaldoCurrencies = $shift->beginningSaldos->pluck('currency');
                            $endSaldoCurrencies = $shift->endSaldos->pluck('currency');
                            
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
                                             $shift->beginningSaldos->pluck('currency')->contains($currency) ||
                                             $shift->endSaldos->pluck('currency')->contains($currency) ||
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
                                    $endSaldo = $mostRecentShift->endSaldos->where('currency', $currency)->first();
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
                Tables\Filters\SelectFilter::make('location')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload(),

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