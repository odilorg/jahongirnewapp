<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Expense;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Filament\Resources\Resource;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Indicator;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Columns\Summarizers\Sum;
use App\Filament\Resources\ExpenseResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ExpenseResource\RelationManagers;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    

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
                Select::make('category_id')
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
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric(),
                Select::make('payment_type')
                    ->options([
                        'naqd' => 'Naqd',
                        'karta' => 'Karta',
                        'perech' => 'Perech'
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
                    ->sortable(),
                Tables\Columns\TextColumn::make('expense_date')
               
                    ->date(),
                    //->sortable(),
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
                Filter::make('expense_date')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                 
                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Created from ' . Carbon::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }
                 
                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Created until ' . Carbon::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }
                 
                        return $indicators;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('expense_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('expense_date', '<=', $date),
                            );
                    })

            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
