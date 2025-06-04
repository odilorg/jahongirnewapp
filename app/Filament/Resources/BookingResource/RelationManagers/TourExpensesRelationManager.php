<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use App\Models\Guide;
use App\Models\Driver;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;

class TourExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'tourExpenses';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                 Select::make('supplier_type')
                    ->label('Supplier Type')
                    ->options([
                        Driver::class => 'Driver',
                        Guide::class => 'Guide',
                        // Add other supplier types if any
                    ])
                    ->reactive()
                    ->required()
                    ->afterStateUpdated(fn (callable $set) => $set('supplier_id', null)),

                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options(function (callable $get) {
                        $type = $get('supplier_type');
                        if ($type === Driver::class) {
                            return Driver::all()->pluck('first_name', 'id');
                        }
                        if ($type === Guide::class) {
                            return Guide::all()->pluck('first_name', 'id');
                        }
                        return [];
                    })
                    ->required()
                    ->searchable(),

                TextInput::make('description')
                    ->label('Description')
                    ->required()
                    ->maxLength(255),

                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                DatePicker::make('expense_date')
                    ->label('Expense Date')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
               TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('usd', true)
                    ->sortable(),

                TextColumn::make('expense_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('supplier_type')
                    ->label('Supplier Type')
                    ->formatStateUsing(fn ($state) => class_basename($state)),

                TextColumn::make('supplier.first_name')
                    ->label('Supplier Name')
                    ->sortable()
                    ->searchable(),

            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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
}
