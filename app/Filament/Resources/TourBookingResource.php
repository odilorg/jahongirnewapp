<?php

namespace App\Filament\Resources;

use App\Models\Car;
use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use App\Models\TourBooking;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Forms\Components\Repeater;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TourBookingResource\Pages;
use App\Filament\Resources\TourBookingResource\RelationManagers;
use App\Filament\Resources\TourBookingResource\RelationManagers\DriverRelationManager;
use App\Filament\Resources\TourBookingResource\RelationManagers\GuestTourBookingRelationManager;

class TourBookingResource extends Resource
{
    protected static ?string $model = TourBooking::class;

    protected static ?string $navigationIcon = 'heroicon-o-bold';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([


                Forms\Components\Section::make('Tour Booking Info')
                    ->description('Add information about Tour Details')
                    ->collapsible()
                    ->schema([

                        Forms\Components\Select::make('guest_id')
                            ->relationship('guest', 'full_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('number_of_adults')
                            ->required()
                            ->numeric(),
                        Forms\Components\TextInput::make('number_of_children')
                            ->required()
                            ->numeric(),

                        Forms\Components\TextInput::make('group_number')
                            ->default('2024-' .  random_int(100000, 999999))
                            ->required()
                            ->maxLength(255),
                    ])->columnSpan(1)->columns(2),
                Forms\Components\Section::make('Tour Details')
                    ->description('Add Tour Related Details')
                    ->collapsible()
                    ->schema([
                        Repeater::make('tourBookingRepeaters')
                            ->label('Tour Details')
                            ->relationship('tourBookingRepeaters')  // Make sure this points to the correct relationship method in the TourBooking model
                            ->schema([

                                Forms\Components\Select::make('tour_id')
                                    ->label('Tour')
                                    ->relationship('tour', 'title')  // Correctly define the driver relationship
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Forms\Components\Select::make('driver_id')
                                    ->label('Driver')
                                    ->relationship('driver', 'full_name')  // Correctly define the driver relationship
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Forms\Components\Select::make('guide_id')
                                    ->label('Guide')
                                    ->relationship('guide', 'full_name')  // Correctly define the driver relationship
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Forms\Components\Textarea::make('special_requests')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('pickup_location')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('dropoff_location')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\ToggleButtons::make('status')
                                    ->options([
                                        'in_progress' => 'In Progress',
                                        'finished' => 'Finished',

                                    ])
                                    ->label('Tour Status')
                                    ->colors([
                                        'finished' => 'success',
                                        'in_progress' => 'danger',


                                    ])
                                    ->columns(2)
                                    ->required(),
                                Forms\Components\ToggleButtons::make('payment_status')
                                    ->options([
                                        'paid' => 'Paid',
                                        'not_paid' => 'NotPaid',
                                        'partially' => 'Partially'
                                    ])
                                    ->colors([
                                        'paid' => 'success',
                                        'not_paid' => 'warning',
                                        'partially' => 'info',

                                    ])
                                    ->columns(3)


                            ])->columns(2),
                    ])->columnSpan(1),



            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group_number')
                ->label('Group')
                    ->searchable(),
                TextColumn::make('guest.full_name')
                ->label('Main Guest'),
                Tables\Columns\TextColumn::make('tourBookingRepeaters.tour.title')
                ->numeric()
                ->sortable(),
                Tables\Columns\TextColumn::make('tourBookingRepeaters.status')
                    ->label('Tour Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'finished' => 'success',
                        'in_progress' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('tourBookingRepeaters.payment_status')
                    ->label('Payment Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'not_paid' => 'warning',
                        'partially' => 'info',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // TextColumn::make('tour.title'),

                // TextColumn::make('drivers.full_name'),
                // TextColumn::make('guest.full_name'), 
                // TextColumn::make('guide.full_name'),    
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
                    // ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('number_of_children')
                    ->numeric()
                    //   ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
              

                // Tables\Columns\TextColumn::make('pickup_location')
                //     ->searchable(),
                // Tables\Columns\TextColumn::make('dropoff_location')
                //     ->searchable(),

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

                Section::make('Relationship Info')
                    // ->description('Prevent abuse by limiting the number of requests per period')
                    ->schema([

                        //  TextEntry::make('cars.model')
                        //  ->label('Car Model'),
                        //  TextEntry::make('cars.number_seats')
                        //  ->label('Number of Seats'),
                        //  TextEntry::make('cars.number_luggage')
                        //  ->label('Number of Luggage'),
                        //  ImageEntry::make('cars.image')
                        //  ->label('Car Image'), 

                        RepeatableEntry::make('tourBookingRepeaters')
                            ->schema([

                                TextEntry::make('payment_status') // Accessing car_plate from the pivot table
                                    ->label('Tour Details')
                                    // ->getStateUsing(fn($record) => $record->pivot?->payment_status) // Fetch car_plate from the pivot table
                                    ->columnSpan(2),
                            ])
                            ->columns(2)


                    ])->columns(2),



            ]);
    }
    public static function getRelations(): array
    {
        return [
            // GuestTourBookingRelationManager::class
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
