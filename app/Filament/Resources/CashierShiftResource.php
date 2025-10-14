<?php

namespace App\Filament\Resources;

use App\Actions\ApproveShiftAction;
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

    public static function getNavigationLabel(): string
    {
        return __('cash.cashier_shifts');
    }

    public static function getModelLabel(): string
    {
        return __('cash.cashier_shifts');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cash.cashier_shifts');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Cash Management';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('user_id')
                    ->default(auth()->id())
                    ->visible(fn () => !auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                Forms\Components\Select::make('cash_drawer_id')
                    ->label(__('cash.cash_drawer'))
                    ->relationship('cashDrawer', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('user_id')
                    ->label(__('cash.user'))
                    ->relationship('user', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager']))
                    ->default(fn () => auth()->id()),
                Forms\Components\Select::make('status')
                    ->label(__('cash.shift_status'))
                    ->options([
                        'open' => __('cash.open_shift'),
                        'closed' => __('cash.closed_shift'),
                    ])
                    ->required(),
                // Multi-currency beginning saldos
                Forms\Components\Section::make(__c('beginning_saldo'))
                    ->description(__c('set_for_each_currency'))
                    ->schema([
                        Forms\Components\TextInput::make('beginning_saldo_uzs')
                            ->label(__c('uzs') . ' ' . __c('amount'))
                            ->numeric()
                            ->prefix('UZS')
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                        Forms\Components\TextInput::make('beginning_saldo_usd')
                            ->label(__c('usd') . ' ' . __c('amount'))
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                        Forms\Components\TextInput::make('beginning_saldo_eur')
                            ->label(__c('eur') . ' ' . __c('amount'))
                            ->numeric()
                            ->prefix('€')
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])),
                        Forms\Components\TextInput::make('beginning_saldo_rub')
                            ->label(__c('rub') . ' ' . __c('amount'))
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
                    ->label(__c('notes'))
                    ->maxLength(1000),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('cashDrawer.name')
                    ->label(__c('cash_drawer'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__c('user'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label(__c('shift_status'))
                    ->colors([
                        'success' => 'open',
                        'gray' => 'closed',
                        'warning' => 'under_review',
                    ]),
                Tables\Columns\TextColumn::make('beginning_saldo')
                    ->label(__c('beginning_saldo'))
                    ->money('UZS')
                    ->sortable(),
                Tables\Columns\TextColumn::make('multi_currency_beginning_saldos')
                    ->label(__c('beginning_saldo'))
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
                    ->label(__c('expected_balance'))
                    ->money('UZS')
                    ->sortable(),
                Tables\Columns\TextColumn::make('counted_end_saldo')
                    ->label(__c('counted_balance'))
                    ->money('UZS')
                    ->sortable(),
                Tables\Columns\TextColumn::make('discrepancy')
                    ->label(__c('discrepancy'))
                    ->money('UZS')
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('opened_at')
                    ->label(__c('opened_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('closed_at')
                    ->label(__c('closed_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_in_hours')
                    ->label(__c('duration') . ' (' . __c('hours') . ')')
                    ->numeric(1)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'closed' => 'Closed',
                        'under_review' => 'Under Review',
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

                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CashierShift $record) =>
                        $record->isUnderReview() && auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])
                    )
                    ->form([
                        Forms\Components\Textarea::make('approval_notes')
                            ->label('Approval Notes')
                            ->rows(3)
                            ->placeholder('Optional notes about this approval'),
                    ])
                    ->action(function (CashierShift $record, array $data) {
                        $approver = new ApproveShiftAction();
                        $approver->approve($record, auth()->user(), $data['approval_notes'] ?? null);

                        return redirect()->route('filament.admin.resources.cashier-shifts.index');
                    })
                    ->successNotificationTitle('Shift approved successfully'),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CashierShift $record) =>
                        $record->isUnderReview() && auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager'])
                    )
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->required()
                            ->rows(3)
                            ->placeholder('Explain why this shift needs to be recounted'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (CashierShift $record, array $data) {
                        $approver = new ApproveShiftAction();
                        $approver->reject($record, auth()->user(), $data['rejection_reason']);

                        return redirect()->route('filament.admin.resources.cashier-shifts.index');
                    })
                    ->successNotificationTitle('Shift rejected - cashier will need to recount'),
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
            'start-shift' => Pages\StartShift::route('/start-shift'),  // MUST be before /{record} routes
            'view' => Pages\ViewCashierShift::route('/{record}'),
            'edit' => Pages\EditCashierShift::route('/{record}/edit'),
            'close-shift' => Pages\CloseShift::route('/{record}/close-shift'),
            'shift-report' => Pages\ShiftReport::route('/{record}/shift-report'),
        ];
    }
}