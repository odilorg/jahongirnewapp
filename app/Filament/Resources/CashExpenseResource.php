<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashExpenseResource\Pages;
use App\Models\CashExpense;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashExpenseResource extends Resource
{
    protected static ?string $model = CashExpense::class;
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Expenses';
    protected static ?string $modelLabel = 'Expense';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('cashier_shift_id')
                ->relationship('shift', 'id')
                ->getOptionLabelFromRecordUsing(fn ($record) => "Shift #{$record->id} - " . ($record->user?->name ?? 'Unknown'))
                ->searchable()
                ->preload(),
            Forms\Components\Select::make('expense_category_id')
                ->relationship('category', 'name')
                ->searchable()
                ->preload(),
            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->required()
                ->prefix('Amount'),
            Forms\Components\Select::make('currency')
                ->options(['UZS' => 'UZS', 'USD' => 'USD', 'EUR' => 'EUR', 'RUB' => 'RUB'])
                ->default('UZS')
                ->required(),
            Forms\Components\Textarea::make('description')
                ->required()
                ->maxLength(500),
            Forms\Components\Toggle::make('requires_approval')
                ->label('Requires Owner Approval'),
            Forms\Components\Textarea::make('rejection_reason')
                ->visible(fn ($record) => $record?->rejected_at !== null),
            Forms\Components\DateTimePicker::make('occurred_at')
                ->default(now()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('currency')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'UZS' => 'info',
                        'USD' => 'warning',
                        'EUR' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn ($record) => strtolower($record->currency ?? 'uzs'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created By'),
                Tables\Columns\IconColumn::make('requires_approval')
                    ->label('Approval')
                    ->boolean(),
                Tables\Columns\TextColumn::make('approval_status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        if (!$record->requires_approval) return 'auto-approved';
                        if ($record->approved_at) return 'approved';
                        if ($record->rejected_at) return 'rejected';
                        return 'pending';
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved', 'auto-approved' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('currency')
                    ->options(['UZS' => 'UZS', 'USD' => 'USD', 'EUR' => 'EUR']),
                Tables\Filters\SelectFilter::make('expense_category_id')
                    ->relationship('category', 'name')
                    ->label('Category')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('requires_approval')
                    ->label('Needs Approval'),
                Tables\Filters\Filter::make('pending')
                    ->label('Pending Approval')
                    ->query(fn (Builder $query) => $query->where('requires_approval', true)->whereNull('approved_at')->whereNull('rejected_at'))
                    ->toggle(),
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('occurred_at', '>=', $date))
                            ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('occurred_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->isPending())
                    ->action(function ($record) {
                        $record->update([
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')->required(),
                    ])
                    ->visible(fn ($record) => $record->isPending())
                    ->action(function ($record, array $data) {
                        $record->update([
                            'rejected_by' => auth()->id(),
                            'rejected_at' => now(),
                            'rejection_reason' => $data['rejection_reason'],
                        ]);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashExpenses::route('/'),
        ];
    }
}
