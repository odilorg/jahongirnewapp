<?php

namespace App\Filament\Resources;

use App\Enums\Currency;
use App\Enums\TransactionType;
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

    protected static ?string $navigationGroup = 'Finance';

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
        return 'Finance';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('cashier_shift_id')
                    ->label('Cashier Shift')
                    ->options(function () {
                        return \App\Models\CashierShift::with('cashDrawer', 'user')
                            ->where('status', 'open')
                            ->get()
                            ->mapWithKeys(fn ($s) => [
                                $s->id => "#{$s->id} - ".($s->cashDrawer?->name ?? 'N/A').' ('.($s->user?->name ?? '?').')',
                            ])
                            ->toArray();
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
                        ->label(__c('currency').' '.__c('cash_in'))
                        ->options(Currency::class)
                        ->default(Currency::UZS)
                        ->required()
                        ->searchable()
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('amount')
                        ->label(__c('amount').' '.__c('cash_in'))
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
                        ->label(__c('currency').' '.__c('cash_out'))
                        ->options(Currency::class)
                        ->searchable()
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('out_amount')
                        ->label(__c('amount').' '.__c('cash_out'))
                        ->numeric()
                        ->minValue(0.01)
                        ->columnSpan(2),
                ])
                    ->columns(3)
                    ->visible(fn ($get) => $get('type') === TransactionType::IN_OUT->value)
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
                    ->required()
                    ->visible(fn () => auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager']))
                    ->helperText('Auto-set on creation (managers can override)'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 💱 Mixed-currency badge — shows on rows that are part of
                // a mixed-currency journal (Phase 1.5.1). Helps finance
                // review at-a-glance without joining tables. Only renders
                // when the column is actually populated, so non-split rows
                // stay clean.
                Tables\Columns\TextColumn::make('mixed_currency_badge')
                    ->label('')
                    ->state(fn ($record): string => $record->base_currency_for_split ? '💱' : '')
                    ->tooltip(fn ($record): ?string => $record->base_currency_for_split
                        ? "Mixed-currency journal · base: {$record->base_currency_for_split} · journal: ".substr((string) $record->journal_entry_id, 0, 8).'…'
                        : null),
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

                Tables\Columns\TextColumn::make('exchange_details')
                    ->label('Exchange Details')
                    ->getStateUsing(function ($record) {
                        if (! $record) {
                            return null;
                        }

                        return $record->getExchangeDetails();
                    })
                    ->badge()
                    ->color('warning')
                    ->visible(fn ($record) => $record && $record->isExchange()),

                Tables\Columns\TextColumn::make('shift.cashDrawer.location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                // Fine-grained income taxonomy — visible only on rows
                // that have it (admin-recorded petty sales). Sortable +
                // searchable so operators can group by sale type.
                Tables\Columns\TextColumn::make('incomeCategory.name')
                    ->label('Income type')
                    ->placeholder('—')
                    ->sortable()
                    ->searchable(),
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
                // 💱 Mixed-currency journals filter — finance review surface
                Tables\Filters\Filter::make('mixed_currency_only')
                    ->label('💱 Mixed-currency only')
                    ->query(fn ($query) => $query->whereNotNull('base_currency_for_split'))
                    ->toggle(),

                // Phase 1, 2026-05-11 — manager reconciliation surface for
                // beds24_external rows that didn't auto-pass the five
                // drawer-truth guards in Beds24WebhookController. Filter
                // pre-narrows the list so the manager can scan exclusion
                // reasons in one view and flip rows individually after
                // confirming with the front desk.
                Tables\Filters\Filter::make('beds24_external_unaccounted')
                    ->label('🏦 Beds24 admin несверенные')
                    ->query(fn ($query) => $query
                        ->where('source_trigger', \App\Enums\CashTransactionSource::Beds24External->value)
                        ->where('counts_as_drawer_truth', false))
                    ->toggle(),
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

                // Phase 1, 2026-05-11 — manager-audited flip of
                // counts_as_drawer_truth on a beds24_external row that
                // the webhook guards excluded. Visible only to
                // super_admin/admin/manager AND only when the row is a
                // candidate (excluded beds24_external). Manager must
                // provide a one-line note so the audit trail captures
                // their reasoning. Writes flipped_by_user_id +
                // flipped_at + note to the row and emits a Log line.
                Tables\Actions\Action::make('flipDrawerTruth')
                    ->label('Учесть в кассе')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Учесть запись в кассе?')
                    ->modalDescription(fn ($record) => sprintf(
                        "Запись #%d — $%s — будет учтена в балансе кассы.\n"
                        .'Причина исключения: %s.',
                        $record->id,
                        number_format((float) $record->amount, 2),
                        $record->drawer_truth_excluded_reason instanceof \App\Enums\DrawerTruthExcludedReason
                            ? $record->drawer_truth_excluded_reason->humanLabel()
                            : ($record->drawer_truth_excluded_reason ?? '—'),
                    ))
                    ->form([
                        Forms\Components\Textarea::make('flip_note')
                            ->label('Причина ручного учёта')
                            ->required()
                            ->rows(2)
                            ->maxLength(255)
                            ->placeholder('Например: сверено с ночным админом, наличные в кассе'),
                    ])
                    ->visible(fn ($record): bool => (auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']) ?? false)
                        && $record->source_trigger === \App\Enums\CashTransactionSource::Beds24External
                        && $record->counts_as_drawer_truth === false)
                    ->action(function ($record, array $data): void {
                        // Business logic lives in FlipDrawerTruthAction
                        // per CLAUDE.md hard line (no Filament closures
                        // > 10 LOC). Closure here is UI-only: invoke
                        // the action, render notification.
                        app(\App\Actions\Cashier\FlipDrawerTruthAction::class)->execute(
                            $record,
                            auth()->user(),
                            (string) ($data['flip_note'] ?? ''),
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Запись учтена в кассе')
                            ->body("#{$record->id} помечена как drawer truth.")
                            ->success()
                            ->send();
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
            'index' => Pages\ListCashTransactions::route('/'),
            'create' => Pages\CreateCashTransaction::route('/create'),
            'edit' => Pages\EditCashTransaction::route('/{record}/edit'),
        ];
    }
}
