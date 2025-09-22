<?php

namespace App\Filament\Resources;

use App\Actions\CloseShiftAction;
use App\Actions\StartShiftAction;
use App\Enums\ShiftStatus;
use App\Enums\Currency;
use App\Filament\Resources\CashierShiftResource\Pages;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashierShiftResource extends Resource
{
    protected static ?string $model = CashierShift::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Cash Management';

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = true;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('user_id')
                    ->default(auth()->id())
                    ->visible(fn () => !auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                Forms\Components\Select::make('cash_drawer_id')
                    ->relationship('cashDrawer', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager']))
                    ->default(fn () => auth()->id()),
                       Forms\Components\Select::make('status')
                           ->options([
                               'open' => 'Open',
                               'closed' => 'Closed',
                           ])
                           ->required(),
                // Multi-currency beginning saldos
                Forms\Components\Section::make('Beginning Cash Amounts')
                    ->description('Set the opening cash amount for each currency')
                    ->schema([
                        Forms\Components\TextInput::make('beginning_saldo_uzs')
                            ->label('UZS Amount')
                            ->numeric()
                            ->prefix('UZS')
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                        Forms\Components\TextInput::make('beginning_saldo_usd')
                            ->label('USD Amount')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                        Forms\Components\TextInput::make('beginning_saldo_eur')
                            ->label('EUR Amount')
                            ->numeric()
                            ->prefix('€')
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                        Forms\Components\TextInput::make('beginning_saldo_rub')
                            ->label('RUB Amount')
                            ->numeric()
                            ->prefix('₽')
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                    ])
                    ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager']))
                    ->collapsible(),
                
                // Legacy field for backward compatibility
                Forms\Components\TextInput::make('beginning_saldo')
                    ->label('Beginning Saldo (UZS) - Legacy')
                    ->numeric()
                    ->prefix('UZS')
                    ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager']))
                    ->helperText('Legacy field - use multi-currency section above'),
                Forms\Components\TextInput::make('expected_end_saldo')
                    ->numeric()
                    ->prefix('UZS')
                    ->disabled(),
                Forms\Components\TextInput::make('counted_end_saldo')
                    ->numeric()
                    ->prefix('UZS'),
                Forms\Components\TextInput::make('discrepancy')
                    ->numeric()
                    ->prefix('UZS')
                    ->disabled(),
                Forms\Components\Textarea::make('discrepancy_reason')
                    ->maxLength(1000),
                Forms\Components\DateTimePicker::make('opened_at')
                    ->required(),
                Forms\Components\DateTimePicker::make('closed_at'),
                Forms\Components\Textarea::make('notes')
                    ->maxLength(1000),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('cashDrawer.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'open',
                        'gray' => 'closed',
                    ]),
                Tables\Columns\TextColumn::make('beginning_saldo')
                    ->money('UZS')
                    ->sortable(),
                Tables\Columns\TextColumn::make('multi_currency_beginning_saldos')
                    ->label('Beginning Saldos')
                    ->getStateUsing(function (CashierShift $record): string {
                        $saldos = $record->beginningSaldos;
                        if ($saldos->isEmpty()) {
                            return 'None';
                        }
                        
                        return $saldos->map(function ($saldo) {
                            return $saldo->formatted_amount;
                        })->join(', ');
                    })
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('expected_end_saldo')
                    ->money('UZS')
                    ->sortable(),
                Tables\Columns\TextColumn::make('counted_end_saldo')
                    ->money('UZS')
                    ->sortable(),
                Tables\Columns\TextColumn::make('discrepancy')
                    ->money('UZS')
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('closed_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_in_hours')
                    ->label('Duration (hrs)')
                    ->numeric(1)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'closed' => 'Closed',
                    ]),
                Tables\Filters\SelectFilter::make('cash_drawer_id')
                    ->relationship('cashDrawer', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('opened_at')
                    ->form([
                        Forms\Components\DatePicker::make('opened_from'),
                        Forms\Components\DatePicker::make('opened_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['opened_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('opened_at', '>=', $date),
                            )
                            ->when(
                                $data['opened_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('opened_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('close')
                    ->label('Close Shift')
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    ->visible(fn (CashierShift $record) => $record->isOpen())
                    ->requiresConfirmation()
                    ->action(function (CashierShift $record) {
                        return redirect()->route('filament.admin.resources.cashier-shifts.close-shift', ['record' => $record->id]);
                    }),
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
            'index' => Pages\ListCashierShifts::route('/'),
            'create' => Pages\CreateCashierShift::route('/create'),
            'edit' => Pages\EditCashierShift::route('/{record}/edit'),
            'start-shift' => Pages\StartShift::route('/start-shift'),
            'close-shift' => Pages\CloseShift::route('/{record}/close-shift'),
            'shift-report' => Pages\ShiftReport::route('/{record}/shift-report'),
        ];
    }
}