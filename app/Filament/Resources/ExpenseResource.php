<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Expense;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Grouping\Group;
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
                Forms\Components\Select::make('hotel_id')
                    ->relationship('hotel', 'name')
                    ->default(session('last_selected_hotel_id')) // Set the default value
                    ->required(),
                   // ->numeric(),
                   Forms\Components\Select::make('category_id')
                   ->relationship('category', 'name')
                   ->createOptionForm([
                    Forms\Components\TextInput::make('name')
                        ->required(),
                   ])
                  // ->default(session('last_selected_hotel_id')) // Set the default value
                 ->required(),   
                Forms\Components\DatePicker::make('expense_date')
                    ->default(now()),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric(),
                Forms\Components\Select::make('payment_type')
                    ->options([
                        'naqd' => 'Naqd',
                        'karta' => 'Karta',
                        'perech' => 'Perech'
                    ])
                    ->required()
                    ->default('naqd')
               
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->groups([
            'payment_type',
            
        ])
        ->defaultGroup('hotel.name')
       

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
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                ->summarize(
                    Sum::make()->formatStateUsing(fn ($state) => $state / 100)
                )
                ->money('UZS')
                   
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('hotel.name')
                    ->numeric()
                    ->sortable(),

                  

            ])
            ->filters([
                //
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
