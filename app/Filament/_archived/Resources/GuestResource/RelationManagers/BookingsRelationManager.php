<?php

namespace App\Filament\Resources\GuestResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use App\Models\Guest;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Booking Information')
                            ->schema([
                                Forms\Components\Hidden::make('guest_id')
                                    ->required()
                                    // ->searchable()
                                    // ->preload()
                                    // ->relationship('guest', 'full_name')
                                    ->default(function () {
                                        // Automatically set the guest_id to the current guest's id
                                        return $this->getOwnerRecord()->id;
                                    }),
                                // ->reactive() // Makes the field reactive to changes
                                // ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                //     // Get the selected guest and update the group_name field
                                //     $guest = Guest::find($state);
                                //     // Concatenate guest full name with tour title if both are available
                                //     if ($guest) {
                                //         $set('group_name', $guest->full_name);
                                //     }
                                // }),
                                Forms\Components\Hidden::make('group_name')
                                    ->label('Group Name')
                                    ->disabled() // Make it read-only so users cannot edit it
                                    ->dehydrated(),
                                //     // /->hiddenOnForm() // Hide this field from the user interface
                                //     ->required()
                                //     ->default(function () {
                                //         // Generate a random 6-digit number and append "UZ"
                                //         return rand(100000, 999999) . 'UZ';
                                //     }),
                                // Forms\Components\Hidden::make('group_name')
                                //     ->default(function () {
                                //         // Retrieve the current guest's full name and set it as the group_name
                                //         $guest = $this->getOwnerRecord();
                                //         return $guest ? $guest->full_name : null; // Set guest's full name or null if not found
                                //     })
                                //     ->required(),

                                Forms\Components\DateTimePicker::make('booking_start_date_time')
                                    ->required()
                                    ->native(false)
                                    ->reactive() // React to changes in the date
                                    ->afterStateUpdated(function (callable $set, callable $get) {
                                        // Retrieve the current guest and booking date
                                        $guest = $this->getOwnerRecord();
                                        $bookingDate = $get('booking_start_date_time')
                                            ? \Carbon\Carbon::parse($get('booking_start_date_time'))->format('d-m-y')  // Format as day-month-year with 2-digit year
                                            : null;

                                        // Ensure both guest and date exist before setting the group_name
                                        if ($guest && $bookingDate) {
                                            // Generate a 5-digit random number
                                            $randomNumber = rand(10000, 99999);
                                            // Set the group_name with formatted date
                                            $set('group_name', $guest->full_name . '-' . $bookingDate . '-' . $randomNumber);
                                        }
                                    }),



                                // Forms\Components\DateTimePicker::make('booking_start_date_time')
                                //     ->required()
                                //     ->native(false)
                                //     ->reactive(), // React to the date being changed,
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
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('group_name')
            ->columns([
                Tables\Columns\TextColumn::make('tour.title')
                    ->searchable(),
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
