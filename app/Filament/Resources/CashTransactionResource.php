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

    public static function getNavigationLabel(): string
    {
        return __('cash.cash_transactions');
    }

    public static function getModelLabel(): string
    {
        return __('cash.cash_transactions');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cash.cash_transactions');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Cash Management';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('cashier_shift_id')
                    ->label(__c('cashier_shifts'))
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
                        $user = auth()->user();
                        
                        // For managers/admins, show all open shifts
                        return \App\Models\CashierShift::with('cashDrawer', 'user')
                            ->where('status', 'open')
                            ->get()
                            ->mapWithKeys(fn ($shift) => [
                                $shift->id => "Shift #{$shift->id} - " . ($shift->cashDrawer?->name ?? 'Unknown Drawer') . " ({$shift->user->name})"
                            ]);
                    })
                    ->default(function () {
                        $user = auth()->user();
                        
                        // Auto-select user's open shift if they're a cashier
                        if ($user->hasRole('cashier')) {
                            $userShift = \App\Models\CashierShift::getUserOpenShift($user->id);
                            return $userShift?->id;
                        }
                        
                        return null;
                    })
                    ->visible(function () {
                        $user = auth()->user();
                        
                        // Hide field completely for cashiers (they don't need to see it)
                        if ($user->hasRole('cashier')) {
                            return false;
                        }
                        
                        return true; // Always show for managers/admins
                    }),
                Forms\Components\Select::make('type')
                    ->label(__c('transaction_type'))
                    ->options(TransactionType::class)
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(function ($state, $set) {
                        // Clear amount when type changes
                        $set('amount', '');
                        $set('out_currency', '');
                        $set('out_amount', '');
                    }),
                       Forms\Components\Group::make([
                           Forms\Components\Select::make('currency')
                               ->label(__c('currency') . ' ' . __c('cash_in'))
                               ->options(Currency::class)
                               ->default(Currency::UZS)
                               ->required()
                               ->searchable()
                               ->columnSpan(1),
                           Forms\Components\TextInput::make('amount')
                               ->label(__c('amount') . ' ' . __c('cash_in'))
                               ->numeric()
                               ->required()
                               ->minValue(0.01)
                               ->columnSpan(2),
                       ])
                       ->columns(3)
                       ->reactive(),
                       
                // Dynamic fields for In-Out transactions
                Forms\Components\Group::make([
                    Forms\Components\Select::make('out_currency')
                        ->label(__c('currency') . ' ' . __c('cash_out'))
                        ->options(Currency::class)
                        ->searchable()
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('out_amount')
                        ->label(__c('amount') . ' ' . __c('cash_out'))
                        ->numeric()
                        ->minValue(0.01)
                        ->columnSpan(2),
                ])
                ->columns(3)
                ->reactive(),
                Forms\Components\Select::make('category')
                    ->label(__c('category'))
                    ->options([
                        'sale' => __c('sale'),
                        'refund' => __c('refund'),
                        'expense' => __c('expense'),
                        'deposit' => __c('deposit'),
                        'change' => __c('change'),
                        'other' => __c('other'),
                    ])
                    ->searchable(),
                Forms\Components\TextInput::make('reference')
                    ->label(__c('reference'))
                    ->maxLength(255),
                Forms\Components\Textarea::make('notes')
                    ->label(__c('notes'))
                    ->maxLength(1000),
                Forms\Components\Select::make('created_by')
                    ->label(__c('created_by'))
                    ->relationship('creator', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->default(auth()->id())
                    ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager']))
                    ->dehydrated(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                Forms\Components\DateTimePicker::make('occurred_at')
                    ->label(__c('transaction_date'))
                    ->default(now())
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shift.id')
                    ->label(__c('shift_id'))
                    ->sortable(),
                       Tables\Columns\TextColumn::make('shift.cashDrawer.name')
                           ->label(__c('cash_drawer'))
                           ->searchable()
                           ->sortable()
                           ->default('Unknown Drawer'),
                       Tables\Columns\TextColumn::make('currency')
                           ->label(__c('currency'))
                           ->badge()
                           ->color(fn ($state) => match ($state) {
                               'UZS' => 'blue',
                               'EUR' => 'green',
                               'USD' => 'orange',
                               'RUB' => 'red',
                               default => 'gray',
                           }),
                Tables\Columns\BadgeColumn::make('type')
                    ->label(__c('transaction_type'))
                    ->colors([
                        'success' => 'in',
                        'danger' => 'out',
                    ]),
                       Tables\Columns\TextColumn::make('amount')
                           ->label(__c('amount'))
                           ->formatStateUsing(function ($state, $record) {
                               return $record->currency->formatAmount($state);
                           })
                           ->sortable(),
                Tables\Columns\BadgeColumn::make('category')
                    ->label(__c('category'))
                    ->colors([
                        'success' => 'sale',
                        'warning' => 'refund',
                        'danger' => 'expense',
                        'info' => 'deposit',
                        'gray' => 'change',
                        'secondary' => 'other',
                    ]),
                Tables\Columns\TextColumn::make('reference')
                    ->label(__c('reference'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label(__c('created_by'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label(__c('transaction_date'))
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