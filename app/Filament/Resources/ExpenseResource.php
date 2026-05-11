<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    // Top-level (no group) directly below Dashboard, above Tour Calendar.
    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('hotel_id')
                    ->relationship('hotel', 'name')
                    ->after(function ($component) {
                        Session::put('last_selected_hotel_id', $component->getState());
                    })
                    ->default(session('last_selected_hotel_id')) // Set the default value
                    ->required(),
                // ->numeric(),
                Select::make('expense_category_id')
                    ->relationship('category', 'name')
                    ->createOptionForm([
                        TextInput::make('name')
                            ->required(),
                    ])
                    ->after(function ($component) {
                        Session::put('last_selected_category_id', $component->getState());
                    })
                    ->default(session('last_selected_category_id'))
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('expense_date')
                    ->after(function ($component) {
                        Session::put('last_selected_expense_date', $component->getState());
                    })
                    ->default(session('last_selected_expense_date'))
                    // Bound the date to a sane window so typos like 2060 or
                    // 1977 (real incident — 4 such rows pre-dated this guard)
                    // are rejected at the form layer. Past 2y is generous
                    // enough for legacy backfill; +1mo handles future-
                    // scheduled expenses without allowing far-future typos.
                    //
                    // Closures on the rule() side recompute at submit time so
                    // a form that sat open overnight cannot drift past the
                    // intended bounds; minDate/maxDate are kept for UX only.
                    ->minDate(now()->subYears(2))
                    ->maxDate(now()->addMonths(1))
                    ->rules([
                        fn () => 'after_or_equal:'.now()->subYears(2)->toDateString(),
                        fn () => 'before_or_equal:'.now()->addMonths(1)->toDateString(),
                    ])
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric()
                    // Block zero/negative amounts. MoneyCast stores value * 100
                    // so user input is in major currency units (sums) — minValue(1)
                    // here means "at least 1 sum", effectively rejecting zero-
                    // amount rows. If a refund needs ledger representation,
                    // enter it as a separate "refund" category, not as zero
                    // or a negative entry.
                    ->minValue(1),
                Select::make('payment_type')
                    ->options([
                        'naqd' => 'Naqd',
                        'karta' => 'Karta',
                        'perech' => 'Perech',
                    ])
                    ->after(function ($component) {
                        Session::put('last_selected_payment_type', $component->getState());
                    })
                    ->default(session('last_selected_payment_type'))
                    ->required(),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table

            ->groups([
                Group::make('expense_date')
                    ->date(),

                // ->defaultSort('desc'),
                'hotel.name',
            ])
            ->defaultGroup('expense_date')

            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('category.name')
                    ->numeric()
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('expense_date')

                    ->date(),
                // ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->summarize(
                        Sum::make()->money('UZS', divideBy: 100)
                    )
                   //

                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('hotel.name')
                    ->numeric()
                    ->sortable(),

            ])

            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('hotel')
                    ->relationship('hotel', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('payment_type')
                    ->options([
                        'naqd' => 'naqd',
                        'karta' => 'karta',

                    ]),
                Filter::make('expense_date')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Created from '.Carbon::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Created until '.Carbon::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expense_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expense_date', '<=', $date),
                            );
                    }),

            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    // Consolidated petty-cash rows are locked. Mistakes are
                    // fixed by Unpost (clears source pointer + soft-deletes
                    // this row) rather than direct edit. Prevents the
                    // expenses ↔ cash_expenses link from drifting.
                    ->visible(fn (Expense $record) => $record->cash_expense_id === null),
                Tables\Actions\Action::make('unpost')
                    ->label('Unpost')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (Expense $record) => $record->cash_expense_id !== null)
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason for unposting')
                            ->required()
                            ->minLength(5)
                            ->maxLength(500)
                            ->helperText('Stored on the petty-cash row for audit.'),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('This soft-deletes the expense row and clears the consolidated link on the source petty-cash row. The petty-cash row can then be re-consolidated.')
                    ->action(fn (Expense $record, array $data) => app(\App\Services\Expenses\ConsolidatePettyCashService::class)
                        ->unpostExpense($record->id, $data['reason'])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
