<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use App\Models\Guest;
use Filament\Forms\Form;
use Filament\Tables\Table;
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
                // Tables\Columns\TextColumn::make('booking_start_date_time')
                //     ->dateTime(),
               
                // Tables\Columns\TextColumn::make('pickup_location')
                //     ->numeric()
                //     ->sortable(),
                // Tables\Columns\TextColumn::make('dropoff_location')
                //     ->searchable(),
               
            ])
            ->filters([
                //
            ])
            ->headerActions([
              //  Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                // Tables\Actions\EditAction::make(),
                // Tables\Actions\DeleteAction::make(),
               Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Tables\Actions\BulkActionGroup::make([
                //     Tables\Actions\DeleteBulkAction::make(),
                // ]),
            ]);
    }
}
