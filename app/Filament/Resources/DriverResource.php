<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Driver;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Fieldset;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use App\Filament\Resources\DriverResource\Pages;
use Filament\Infolists\Components\RepeatableEntry;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\DriverResource\RelationManagers;
use App\Filament\Resources\DriverResource\RelationManagers\CarRelationManager;
use App\Filament\Resources\DriverResource\RelationManagers\SupplierPaymentsRelationManager;


class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationGroup = 'Tour Details';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('last_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone01')
                    ->label('Phone number #1')
                    ->tel()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone02')
                    ->label('Phone number #2')
                    ->tel()
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('fuel_type')
                    ->options([
                        'propane' => 'Propane',
                        'methane' => 'Methane',
                        'benzin' => 'Benzin',
                    ])
                    ->required(),
                   
                Forms\Components\FileUpload::make('driver_image')
                    ->image()
                    ->required(),
                Forms\Components\Select::make('cars')
                        ->relationship('cars','model')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                    
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
                Tables\Columns\TextColumn::make('first_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone01')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone02')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fuel_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cars.model'),
                
                Tables\Columns\ImageColumn::make('driver_image')
                    ->circular(),
               
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

            Section::make('Driver Info')
               // ->description('Prevent abuse by limiting the number of requests per period')
                ->schema([
                    TextEntry::make('full_name'),
                    TextEntry::make('email'),
                    TextEntry::make('phone01'),
                    TextEntry::make('phone02'),
                    TextEntry::make('fuel_type'),
                    ImageEntry::make('driver_image') 
                ])->columns(2),
                

           

            Section::make('Relationship Info')
            // ->description('Prevent abuse by limiting the number of requests per period')
             ->schema([
                 
                 TextEntry::make('cars.model')
                 ->label('Car Model'),
                 TextEntry::make('cars.number_seats')
                 ->label('Number of Seats'),
                 TextEntry::make('cars.number_luggage')
                 ->label('Number of Luggage'),
                 ImageEntry::make('cars.image')
                 ->label('Car Image'), 
             ])->columns(2),
            
            
                    

         ]);
    }

    public static function getRelations(): array
    {
        return [
        //    CarRelationManager::class,
        //    SupplierPaymentsRelationManager::class

        ];

    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
          //  'view' => Pages\ViewDriver::route('/{record}'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
