<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Guest;
use Filament\Forms\Form;
use Filament\Tables\Table;
use App\Models\TourPayment;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TourPaymentResource\Pages;
use App\Filament\Resources\TourPaymentResource\RelationManagers;

class TourPaymentResource extends Resource
{
    protected static ?string $model = TourPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
               
                Forms\Components\Select::make('tour_booking_id')
                    ->relationship(name: 'tour_booking', titleAttribute: 'group_number')
                    ->preload()
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('amount_paid')
                    ->required()
                    ->numeric(),
                Forms\Components\DatePicker::make('payment_date')
                   ->native(false) 
                  ->displayFormat('d/m/Y') 
                
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
                Tables\Columns\TextColumn::make('payment_date')  
                    ->numeric() 
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tour_booking.group_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tour_booking.guests.full_name')
                    ->numeric()
                    ->sortable(),    
                Tables\Columns\TextColumn::make('amount_paid')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tour_booking.tours.title')
                    ->numeric()
                    ->sortable(),    
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

            Section::make('Tour Booking Payment')
               // ->description('Prevent abuse by limiting the number of requests per period')
                ->schema([
                    TextEntry::make('payment_date')
                    ->date(),
                    TextEntry::make('amount_paid')
                    ->money('USD')
                   // ->label('Tour Title'),
                    // TextEntry::make('tour_booking.tour.tour_duration')
                    // ->label('Tour Duration'),
                   
                ])->columns(2),
            
            Section::make('Booked Tour Info')
               // ->description('Prevent abuse by limiting the number of requests per period')
                ->schema([
                    TextEntry::make('tour_booking.tour.title')
                    ->label('Tour Title'),
                    TextEntry::make('tour_booking.tour.tour_duration')
                    ->label('Tour Duration'),
                   
                ])->columns(2),
           
            Section::make('Tour Booking Info')
                // ->description('Prevent abuse by limiting the number of requests per period')
                 ->schema([
                    TextEntry::make('tour_booking.group_number')
                        ->label('Group Number'),
                     TextEntry::make('tour_booking.number_of_adults')
                        ->label('Number of adults'),
                     TextEntry::make('tour_booking.number_of_children')
                        ->label('Number of children'),
                     TextEntry::make('tour_booking.pickup_location')
                        ->label('Pickup Location'),
                     TextEntry::make('tour_booking.dropoff_location')
                        ->label('Dropoff location'),
                    
                 ])->columns(2),

            Section::make('Guide Info')
                 // ->description('Prevent abuse by limiting the number of requests per period')
                  ->schema([
                      TextEntry::make('tour_booking.guide.full_name')
                      ->label('Guide Name'),
                      TextEntry::make('tour_booking.guide.email')
                      ->label('Guide email'),
                      TextEntry::make('tour_booking.guide.phone01')
                      ->label('Guide phone #1'),
                      TextEntry::make('tour_booking.guide.phone02')
                      ->label('Guide phone #2'),
                      TextEntry::make('tour_booking.guide.lang_spoken')
                      ->label('Guide Languages spoken'),
                      ImageEntry::make('tour_booking.guide.guide_image')
                      ->label('Guide Image'),
                     
                  ])->columns(2),    
            Section::make('Driver Info')
                  // ->description('Prevent abuse by limiting the number of requests per period')
                   ->schema([
                       TextEntry::make('tour_booking.driver.full_name')
                       ->label('Driver Name'),
                       TextEntry::make('tour_booking.driver.email')
                       ->label('Driver email'),
                       TextEntry::make('tour_booking.driver.phone01')
                       ->label('Driver phone #1'),
                       TextEntry::make('tour_booking.driver.phone02')
                       ->label('Driver phone #2'),
                       TextEntry::make('tour_booking.driver.fuel_type')
                       ->label('Driver car fuel type'),
                       ImageEntry::make('tour_booking.driver.driver_image')
                       ->label('Driver Image'),
                      
                   ])->columns(2),   
            Section::make('Car Info')
                   // ->description('Prevent abuse by limiting the number of requests per period')
                    ->schema([
                        TextEntry::make('tour_booking.driver.car.model')
                        ->label('Car Model'),
                        TextEntry::make('tour_booking.driver.car.number_seats')
                        ->label('Number of Seats'),
                        TextEntry::make('tour_booking.driver.car.number_luggage')
                        ->label('Number of Luggage'),
                        ImageEntry::make('tour_booking.driver.car.image')
                        ->label('Car Image'), 
                    ])->columns(2)                    

             

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
            'index' => Pages\ListTourPayments::route('/'),
            'create' => Pages\CreateTourPayment::route('/create'),
            'edit' => Pages\EditTourPayment::route('/{record}/edit'),
            'view' => Pages\ViewTourPayment::route('/{record}'),
        ];
    }
}
