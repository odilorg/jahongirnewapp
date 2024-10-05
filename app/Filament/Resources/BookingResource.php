<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\Tour;
use Filament\Tables;
use App\Models\Guest;
use App\Models\Booking;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\BookingResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\BookingResource\RelationManagers;
use App\Filament\Resources\BookingResource\RelationManagers\DriverRelationManager;
use App\Filament\Resources\BookingResource\RelationManagers\DriversRelationManager;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Booking Information')
                            ->schema([
                                Forms\Components\Select::make('guest_id')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->relationship('guest', 'full_name')
                                    ->reactive() // Makes the field reactive to changes
                                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                        // Get the selected guest and update the group_name field
                                        $guest = Guest::find($state);
                                        // Concatenate guest full name with tour title if both are available
                                        if ($guest) {
                                            $set('group_name', $guest->full_name);
                                        }
                                    }),
                                Forms\Components\Hidden::make('group_name')
                                    ->label('Group Name')
                                    ->disabled() // Make it read-only so users cannot edit it
                                    ->dehydrated()
                                    // /->hiddenOnForm() // Hide this field from the user interface
                                    ->required(),
                                // ->default(function () {
                                //     // Generate a random 6-digit number and append "UZ"
                                //     return rand(100000, 999999) . 'UZ';
                                // }),  
                                Forms\Components\DateTimePicker::make('booking_start_date_time')
                                    ->required()
                                    ->native(false),
                                Forms\Components\Select::make('guide_id')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->relationship('guide', 'full_name'),
                                    Forms\Components\Select::make('tour_id')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->relationship('tour', 'title'),
                                Forms\Components\Select::make('driver_id')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->relationship('driver', 'full_name'),
                                Forms\Components\TextInput::make('pickup_location')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('dropoff_location')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('special_requests')
                                    ->maxLength(65535)
                                    ->columnSpanFull(),

                            ])->columns(2),




                    ])->columnSpan(2)

            ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group_name')
                ->searchable(),
                Tables\Columns\TextColumn::make('booking_start_date_time')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pickup_location')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dropoff_location')
                    ->searchable(),
               
              
                    Tables\Columns\TextColumn::make('special_requests')
                    ->searchable()
                    ->limit(20),
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
            //   DriversRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
