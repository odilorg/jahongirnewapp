<?php

namespace App\Filament\Resources;

use App\Models\Car;
use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Resources\CarResource\Pages;
use Filament\Infolists\Components\ImageEntry;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\CarResource\RelationManagers;
use App\Filament\Resources\DriverResource\RelationManagers\DriversRelationManager;
use App\Filament\Resources\CarBrandResource\RelationManagers\CarBrandRelationManager;

class CarResource extends Resource
{
    protected static ?string $model = Car::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Driver and Guide Details';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('plate_number')
                    ->required()
                    ->maxLength(255),


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
                    Forms\Components\TextInput::make('color')
                    ->required(),
                Forms\Components\Select::make('driver_id')
                    ->required()
                    //  ->maxLength(255)
                    ->searchable()
                    ->preload()
                    ->relationship('driver', 'full_name'),


                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->required(),
               
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
                Tables\Columns\TextColumn::make('plate_number')
                    ->label('Plate')
                    ->searchable(),
                Tables\Columns\TextColumn::make('carBrand.number_seats')
                    ->label('Seats')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('carBrand.number_luggage')
                    ->label('luggage')
                    ->numeric()
                    ->sortable(),
            Tables\Columns\ImageColumn::make('image')
                    ->label('Preview')
                    ->url(fn ($record) => asset('storage/' . $record->image)) // Make the full image URL accessible
                    ->openUrlInNewTab() // Allow viewing the full image in a new tab
                   ->circular()
                    ->height(50) // Adjust thumbnail height
                    ->width(50),// Adjust thumbnail width
                
                    Tables\Columns\TextColumn::make('color')
                        ->label('Color')
//                        ->sortable(),    
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist


            ->schema([

                Section::make('Car Info')
                    // ->description('Prevent abuse by limiting the number of requests per period')
                    ->schema([
                        TextEntry::make('plate_number'),
                        TextEntry::make('carBrand.brand_name'),

                        TextEntry::make('carBrand.number_seats')
                            ->label('Number of seats'),
                        TextEntry::make('carBrand.number_luggage')
                            ->label('Number of luggage'),
                        ImageEntry::make('image')
                    ])->columns(2)


            ]);
    }

    public static function getRelations(): array
    {
        return [
            //DriversRelationManager::class,


        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCars::route('/'),
            'create' => Pages\CreateCar::route('/create'),
            //    'view' => Pages\ViewCar::route('/{record}'),
            'edit' => Pages\EditCar::route('/{record}/edit'),
        ];
    }
}
