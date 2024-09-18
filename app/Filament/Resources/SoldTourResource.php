<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\Tour;
use Filament\Tables;
use App\Models\Driver;
use App\Models\SoldTour;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\SoldTourResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\SoldTourResource\RelationManagers;

class SoldTourResource extends Resource
{
    protected static ?string $model = SoldTour::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\Section::make('Tour Details')
                    ->description('Add Tour Related Details')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Select::make(name: 'tour_id')
                           // ->live()
                            ->label('Choose Tour')
                            //->dehydrated()
                            ->options(Tour::pluck('title', 'id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('pickup_location')
                            ->required()
                            ->maxLength(255), 
                        Forms\Components\TextInput::make('dropoff_location')
                            ->required()
                            ->maxLength(255), 
                            Forms\Components\Textarea::make('special_request')
                         //   ->required()
                            ->maxLength(1000),             
                    ]),



                Repeater::make('drivers')
                    ->relationship('tourRepeaterDrivers')
                    ->schema([
                        Forms\Components\Select::make('driver_id')
                           // ->live()
                            ->label('Driver')
                          //  ->dehydrated()
                            ->options(Driver::pluck('full_name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\TextInput::make('amount_paid')
                            ->prefix('$')
                           // ->required()
                            ->numeric(),
                        Forms\Components\DatePicker::make('payment_date'),
                         //   ->required()
                            //->maxDate(now()),
                            Forms\Components\FileUpload::make('payment_document_image'),
                        Forms\Components\Select::make('payment_method')
                            ->options([
                                'cash' => 'Cash',
                                'transfer' => 'Transfer',
                                'banktransfer' => 'Bank Transfer',
                            ])
                         //   ->required(),



                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tour.title'),
                Tables\Columns\TextColumn::make('special_request'),
               // Tables\Columns\TextColumn::make('tourRepeaterDrivers.amount_paid'),
                Tables\Columns\TextColumn::make('tourRepeaterDrivers.driver.first_name'),
    
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
            'index' => Pages\ListSoldTours::route('/'),
            'create' => Pages\CreateSoldTour::route('/create'),
            'edit' => Pages\EditSoldTour::route('/{record}/edit'),
        ];
    }
}
