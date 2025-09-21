<?php

namespace App\Filament\Resources;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Enums\Currency;
use App\Filament\Resources\CashTransactionResource\Pages;
use App\Models\CashTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashTransactionResource extends Resource
{
    protected static ?string $model = CashTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Cash Management';

    protected static ?int $navigationSort = 3;

    protected static bool $shouldRegisterNavigation = true;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                       Forms\Components\Select::make('cashier_shift_id')
                           ->relationship('shift', 'id')
                           ->getOptionLabelFromRecordUsing(fn ($record) => "Shift #{$record->id} - " . ($record->cashDrawer?->name ?? 'Unknown Drawer'))
                           ->required()
                           ->searchable()
                           ->preload()
                           ->reactive()
                           ->afterStateUpdated(function ($state, $set) {
                               // Clear amount when shift changes to force re-render
                               $set('amount', '');
                           })
                           ->options(function () {
                               return \App\Models\CashierShift::with('cashDrawer')
                                   ->where('status', 'open')
                                   ->get()
                                   ->mapWithKeys(fn ($shift) => [
                                       $shift->id => "Shift #{$shift->id} - " . ($shift->cashDrawer?->name ?? 'Unknown Drawer')
                                   ]);
                           }),
                Forms\Components\Select::make('type')
                    ->options([
                        'in' => 'Cash In',
                        'out' => 'Cash Out',
                    ])
                    ->required()
                    ->reactive(),
                       Forms\Components\Group::make([
                           Forms\Components\Select::make('currency')
                               ->label('Currency')
                               ->options(Currency::class)
                               ->default(Currency::UZS)
                               ->required()
                               ->searchable()
                               ->columnSpan(1),
                           Forms\Components\TextInput::make('amount')
                               ->label('Amount')
                               ->numeric()
                               ->required()
                               ->minValue(0.01)
                               ->columnSpan(2),
                       ])
                       ->columns(3)
                       ->reactive(),
                Forms\Components\Select::make('category')
                    ->options([
                        'sale' => 'Sale',
                        'refund' => 'Refund',
                        'expense' => 'Expense',
                        'deposit' => 'Deposit',
                        'change' => 'Change',
                        'other' => 'Other',
                    ])
                    ->searchable(),
                Forms\Components\TextInput::make('reference')
                    ->maxLength(255),
                Forms\Components\Textarea::make('notes')
                    ->maxLength(1000),
                Forms\Components\Select::make('created_by')
                    ->relationship('creator', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->default(auth()->id()),
                Forms\Components\DateTimePicker::make('occurred_at')
                    ->default(now())
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shift.id')
                    ->label('Shift ID')
                    ->sortable(),
                       Tables\Columns\TextColumn::make('shift.cashDrawer.name')
                           ->label('Drawer')
                           ->searchable()
                           ->sortable()
                           ->default('Unknown Drawer'),
                       Tables\Columns\TextColumn::make('currency')
                           ->label('Currency')
                           ->badge()
                           ->color(fn ($state) => match ($state) {
                               'UZS' => 'blue',
                               'EUR' => 'green',
                               'USD' => 'orange',
                               'RUB' => 'red',
                               default => 'gray',
                           }),
                Tables\Columns\BadgeColumn::make('type')
                    ->colors([
                        'success' => 'in',
                        'danger' => 'out',
                    ]),
                       Tables\Columns\TextColumn::make('amount')
                           ->formatStateUsing(function ($state, $record) {
                               return $record->currency->formatAmount($state);
                           })
                           ->sortable(),
                Tables\Columns\BadgeColumn::make('category')
                    ->colors([
                        'success' => 'sale',
                        'warning' => 'refund',
                        'danger' => 'expense',
                        'info' => 'deposit',
                        'gray' => 'change',
                        'secondary' => 'other',
                    ]),
                Tables\Columns\TextColumn::make('reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created By')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'in' => 'Cash In',
                        'out' => 'Cash Out',
                    ]),
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'sale' => 'Sale',
                        'refund' => 'Refund',
                        'expense' => 'Expense',
                        'deposit' => 'Deposit',
                        'change' => 'Change',
                        'other' => 'Other',
                    ]),
                Tables\Filters\SelectFilter::make('shift.cash_drawer_id')
                    ->relationship('shift.cashDrawer', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('created_by')
                    ->relationship('creator', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('occurred_at')
                    ->form([
                        Forms\Components\DatePicker::make('occurred_from'),
                        Forms\Components\DatePicker::make('occurred_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['occurred_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('occurred_at', '>=', $date),
                            )
                            ->when(
                                $data['occurred_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('occurred_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListCashTransactions::route('/'),
            'create' => Pages\CreateCashTransaction::route('/create'),
            'edit' => Pages\EditCashTransaction::route('/{record}/edit'),
        ];
    }
}