<?php

namespace App\Filament\Resources\GuideResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use App\Models\Guest;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Forms\Components\Radio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\SelectColumn;



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
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('first_name')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('last_name')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('email')
                                            ->email()
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('phone')
                                            ->tel()
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('country')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('number_of_people')
                                            ->required()
                                            ->numeric(),
                                    ])
                                    ->preload()
                                    ->relationship('guest', 'full_name')
                                    ->reactive()
                                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                        $guest = Guest::find($state);
                                        if ($guest) {
                                            $set('group_name', $guest->full_name);
                                        }
                                    }),

                                    Forms\Components\Hidden::make('booking_number')
                                    ->disabled()
                                    ->dehydrated(fn ($get) => filled($get('booking_number'))) // only save if filled
                                    ->required(fn ($context) => $context === 'edit'), // required only on edit

                                Forms\Components\DateTimePicker::make('booking_start_date_time')
                                    ->label('Tour Start Date & Time')
                                    ->required()
                                    ->native(false),

                                Forms\Components\Select::make('guide_id')
                                    ->searchable()
                                    ->preload()
                                    ->relationship('guide', 'full_name'),

                                Forms\Components\Select::make('tour_id')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->relationship('tour', 'title'),

                                Forms\Components\Select::make('driver_id')
                                    ->searchable()
                                    ->preload()
                                    ->relationship('driver', 'full_name'),

                                Forms\Components\TextInput::make('pickup_location')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('dropoff_location')
                                    ->required()
                                    ->maxLength(255),

                                Radio::make('booking_status')
                                    ->label('Booking Status')
                                    ->options([
                                        'pending' => 'Pending',
                                        'in_progress' => 'in Progress',
                                        'finished' => 'Finished',
                                    ]),

                                Radio::make('booking_source')
                                    ->label('Booking Source')
                                    ->options([
                                        'viatour' => 'Viatour',
                                        'geturguide' => 'GetUrGuide',
                                        'website' => 'Website',
                                        'walkin' => 'Walk In',
                                        'phone' => 'Phone',
                                        'email' => 'Email',
                                        'other' => 'Other',
                                    ])
                                    ->columns(2),

                                Forms\Components\Textarea::make('special_requests')
                                    ->maxLength(65535),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('tour.title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('group_name')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
               // Tables\Actions\EditAction::make(),
               // Tables\Actions\DeleteAction::make(),
                Tables\Actions\ViewAction::make(),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
