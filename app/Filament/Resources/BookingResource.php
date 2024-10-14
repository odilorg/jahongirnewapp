<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\Tour;
use Filament\Tables;
use App\Models\Guest;
use App\Models\Booking;
use Filament\Infolists;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Resources\BookingResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\BookingResource\RelationManagers;
use App\Filament\Resources\BookingResource\RelationManagers\DriverRelationManager;
use App\Filament\Resources\BookingResource\RelationManagers\DriversRelationManager;


class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Tour Details';

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
                                Forms\Components\Select::make('payment_status')
                                    ->required()
                                    ->options([
                                        'paid' => 'Paid',
                                        'not_paid' => 'Not Paid',
                                    ])
                                    ->default('not paid')  // Set the default option to 'Not Paid'
                                    ->label('Payment Status'),
                                Forms\Components\Select::make('payment_method')
                                    ->required()
                                    ->options([
                                        'cash' => 'Cash',
                                        'card' => 'Card',
                                        'paypal' => 'PayPal',
                                        'bank' => 'Bank Transfer',
                                        'stripe' => 'Stripe',
                                    ])
                                    ->default('not paid')  // Set the default option to 'Not Paid'
                                    ->label('Payment Status'),
                                Forms\Components\TextInput::make('amount')
                                    ->numeric()
                                    ->prefix('$')
                                    ->maxValue(42949672.95),
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
                                        'finished' => 'Finished'
                                    ]),
                                    
                                Radio::make('booking_source')
                                ->label('Booking Source')
                                ->options([
                                    'viatour' => 'Viatour',
                                    'geturguide' => 'GetUrGuide',
                                    'website' => 'Website',
                                    'walkin' => 'Walk In',
                                ])->columns(2),

                                Forms\Components\Textarea::make('special_requests')
                                    ->maxLength(65535)
                                //->columnSpanFull(),


                            ])->columns(2),




                    ])->columnSpan(2)

            ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // TextColumn::make('booking_status')
                //     ->label('Status')
                //     ->badge()
                //     ->color(fn (string $state): string => match ($state) {

                //         'in_progress' => 'warning',
                //         'finished' => 'success',
                //         'pending' => 'danger',
                //     }),
                SelectColumn::make('booking_status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'in Progress',
                        'finished' => 'Finished'
                    ]),
                Tables\Columns\TextColumn::make('tour.title')
                    ->searchable()
                    ->limit(20),
                Tables\Columns\TextColumn::make('group_name')
                    ->label('Group')
                    ->searchable(),
                Tables\Columns\TextColumn::make('booking_start_date_time')
                    ->label('Start DT')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('Paym St')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'paid' => 'success',

                        'not_paid' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money('USD')
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
                    ->label('Pickup')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dropoff_location')
                    ->label('Dropoff')
                    ->searchable(),
                Tables\Columns\TextColumn::make('special_requests')
                    ->label('Note')
                    ->searchable()
                    ->limit(20),
                Tables\Columns\TextColumn::make('booking_source')
                    ->label('Source')
                    ->searchable(),
            ])

            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
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
                Infolists\Components\Section::make('Booking details')
                    // ->description('Prevent abuse by limiting the number of requests per period')
                    ->schema([
                        Infolists\Components\TextEntry::make('tour.title'),
                        Infolists\Components\TextEntry::make('booking_start_date_time'),
                        Infolists\Components\TextEntry::make('pickup_location'),
                        TextEntry::make('dropoff_location'),
                        TextEntry::make('special_requests')


                    ])->columns(2)

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
            // 'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
