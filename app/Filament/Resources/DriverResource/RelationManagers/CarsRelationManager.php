<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;

class CarsRelationManager extends RelationManager
{
    protected static string $relationship = 'cars';

    public function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\Select::make('car_brand_id')
                    ->required()
                    //  ->maxLength(255)
                    ->searchable()
                    ->preload()
                    ->relationship('carBrand', 'brand_name')
                    ->createOptionForm([
                        Forms\Components\TextInput::make('brand_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('number_seats')
                            ->required()
                            ->numeric(),
                        Forms\Components\TextInput::make('number_luggage')
                            ->required()
                            ->numeric(),
                    ]),
                TextInput::make('plate_number')
                    ->required(),
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('model')
            ->columns([
                Tables\Columns\TextColumn::make('carBrand.brand_name'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('carBrand.number_seats')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('carBrand.number_luggage')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\ImageColumn::make('image')
                    ->circular(),
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
