<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use App\Models\TourBooking;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TourBookingResource\Pages;
use App\Filament\Resources\TourBookingResource\RelationManagers;
use App\Filament\Resources\TourBookingResource\RelationManagers\DriverRelationManager;

class TourBookingResource extends Resource
{
    protected static ?string $model = TourBooking::class;

    protected static ?string $navigationIcon = 'heroicon-o-bold';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('tours')
                    ->relationship('tours','title')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required(), 
                Forms\Components\Select::make('drivers')
                    ->relationship('drivers','full_name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required(), 
                   
                Forms\Components\Select::make('guests')
                        ->relationship('guests','full_name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required(),   
                Forms\Components\Select::make('guides')
                        ->relationship('guides','full_name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required(),           
               
                    
                // Forms\Components\Select::make('guest_id')
                //         ->relationship(name: 'guest', titleAttribute: 'full_name')
                //         ->preload()
                //         ->searchable()
                //         ->required(),
                // Forms\Components\Select::make('driver_id')
                //         ->relationship(name: 'driver', titleAttribute: 'full_name')
                //         ->preload()
                //         ->searchable()
                //         ->required(),
                // Forms\Components\Select::make('guide_id')
                //         ->relationship(name: 'guide', titleAttribute: 'full_name')
                //         ->preload()
                //         ->searchable()
                //         ->required(),
                Forms\Components\TextInput::make('number_of_adults')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('number_of_children')
                    ->required()
                    ->numeric(),
                Forms\Components\Textarea::make('special_requests')
                    ->required()
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('pickup_location')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('dropoff_location')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('group_number')
                    ->default('2024-' .  random_int(100000, 999999))
                    ->required()
                    ->maxLength(255),    
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
                TextColumn::make('tours.title'),
                TextColumn::make('drivers.full_name'),
                TextColumn::make('guests.full_name'), 
                TextColumn::make('guides.full_name'),    
                // Tables\Columns\TextColumn::make('tour.title')
                   
                //     ->sortable(),
                // Tables\Columns\TextColumn::make('guest.full_name')
                    
                //     ->sortable(),
                // Tables\Columns\TextColumn::make('driver.full_name')
                    
                //     ->sortable(),
                // Tables\Columns\TextColumn::make('guide.full_name')
                //     ->numeric()
                //     ->sortable(),
                Tables\Columns\TextColumn::make('number_of_adults')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('number_of_children')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('pickup_location')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dropoff_location')
                    ->searchable(),
                Tables\Columns\TextColumn::make('group_number')
                    ->searchable(),    
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

            // Section::make('Booked Tour Info')
            //    // ->description('Prevent abuse by limiting the number of requests per period')
            //     ->schema([
            //         TextEntry::make('tour.title')
            //         ->label('Tour Title'),
            //         TextEntry::make('tour.tour_duration')
            //         ->label('Tour Duration'),
                   
            //     ])->columns(2),
           
            // Section::make('Tour Booking Info')
            //     // ->description('Prevent abuse by limiting the number of requests per period')
            //      ->schema([
            //          TextEntry::make('number_of_adults'),
            //          TextEntry::make('number_of_children'),
            //          TextEntry::make('pickup_location'),
            //          TextEntry::make('dropoff_location'),
                    
            //      ])->columns(2),

            // Section::make('Guide Info')
            //      // ->description('Prevent abuse by limiting the number of requests per period')
            //       ->schema([
            //           TextEntry::make('guide.full_name')
            //           ->label('Guide Name'),
            //           TextEntry::make('guide.email')
            //           ->label('Guide email'),
            //           TextEntry::make('guide.phone01')
            //           ->label('Guide phone #1'),
            //           TextEntry::make('guide.phone02')
            //           ->label('Guide phone #2'),
            //           TextEntry::make('guide.lang_spoken')
            //           ->label('Guide Languages spoken'),
            //           ImageEntry::make('guide.guide_image')
            //           ->label('Guide Image'),
                     
            //       ])->columns(2),    
            // Section::make('Driver Info')
            //       // ->description('Prevent abuse by limiting the number of requests per period')
            //        ->schema([
            //            TextEntry::make('driver.full_name')
            //            ->label('Driver Name'),
            //            TextEntry::make('driver.email')
            //            ->label('Driver email'),
            //            TextEntry::make('driver.phone01')
            //            ->label('Driver phone #1'),
            //            TextEntry::make('driver.phone02')
            //            ->label('Driver phone #2'),
            //            TextEntry::make('driver.fuel_type')
            //            ->label('Driver car fuel type'),
            //            ImageEntry::make('driver.driver_image')
            //            ->label('Driver Image'),
                      
            //        ])->columns(2),   
            // Section::make('Car Info')
            //        // ->description('Prevent abuse by limiting the number of requests per period')
            //         ->schema([
            //             TextEntry::make('driver.car.model')
            //             ->label('Car Model'),
            //             TextEntry::make('driver.car.number_seats')
            //             ->label('Number of Seats'),
            //             TextEntry::make('driver.car.number_luggage')
            //             ->label('Number of Luggage'),
            //             ImageEntry::make('driver.car.image')
            //             ->label('Car Image'), 
            //         ])->columns(2)                    

             

         ]);
    }
    public static function getRelations(): array
    {
        return [
           // DriverRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTourBookings::route('/'),
            'create' => Pages\CreateTourBooking::route('/create'),
            'view' => Pages\ViewTourBooking::route('/{record}'),
            'edit' => Pages\EditTourBooking::route('/{record}/edit'),
        ];
    }
}
