<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\Tour;
use Filament\Tables;
use App\Models\Guest;
use App\Models\Guide;
use App\Models\Driver;
use App\Models\SoldTour;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Support\RawJs;
use App\Models\SpokenLanguage;
use Filament\Resources\Resource;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\SoldTourResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\SoldTourResource\RelationManagers;

class SoldTourResource extends Resource
{
    protected static ?string $model = SoldTour::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\Section::make('Tour Details')
                    ->description('Add Tour Related Details')
                    ->collapsible()
                    ->schema([
                        // Guest select field
                        Forms\Components\Select::make('guest_id')
                            ->options(Guest::pluck('full_name', 'id')) // Fetching guest full names
                            ->label('Choose Guest')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive() // Makes the field reactive to changes
                            ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                // Get the selected guest and update the group_name field
                                $guest = Guest::find($state);
                                $tourTitle = $get('tour_id') ? Tour::find($get('tour_id'))->title : '';

                                // Concatenate guest full name with tour title if both are available
                                if ($guest) {
                                    $set('group_name', $guest->full_name . ($tourTitle ? ' - ' . $tourTitle : ''));
                                }
                            }),

                        // Tour select field
                        Forms\Components\Select::make('tour_id')
                            ->label('Choose Tour')
                            ->options(Tour::pluck('title', 'id')) // Fetching tour titles
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive() // Makes the field reactive to changes
                            ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                // Get the selected tour and update the group_name field
                                $tour = Tour::find($state);
                                $guestName = $get('guest_id') ? Guest::find($get('guest_id'))->full_name : '';

                                // Concatenate guest full name with tour title if both are available
                                if ($tour) {
                                    $set('group_name', ($guestName ? $guestName . ' - ' : '') . $tour->title);
                                }
                            }),
                            Forms\Components\Select::make('driver_id')
                            ->label('Choose Driver')
                            ->options(Driver::pluck('full_name', 'id')) // Fetching tour titles
                            ->searchable()
                            ->preload()
                            ->required(),
                            Forms\Components\Select::make('guide_id')
                            ->label('Choose Guide')
                            ->options(Guide::pluck('full_name', 'id')) // Fetching tour titles
                            ->searchable()
                            ->preload()
                            ->required(),
                        // Hidden text field to store the tour name based on the selected tour_id
                        Forms\Components\Hidden::make('group_name')
                            ->label('Group Name')
                            //   / ->disabled() // Make it read-only so users cannot edit it
                            // /->hiddenOnForm() // Hide this field from the user interface
                            ->required()
                            ->default('No Title Available'),
                        Forms\Components\DatePicker::make('booked_date')
                           ->required()
                           ->native(0),
                           Forms\Components\TextInput::make('amount_paid')
                             ->prefix('$')
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->numeric(),
                        Forms\Components\TextInput::make('pickup_location')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('dropoff_location')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('special_request')
                            //   ->required()
                            ->maxLength(1000),

                    ])->columns(2),

                // Forms\Components\Section::make('Guest Details')
                //     ->description('Add Tour Related Guest Details')
                //     ->collapsible()
                //     ->schema([
                //         Forms\Components\Select::make(name: 'guest_id')
                //             ->relationship(name: 'guest', titleAttribute: 'full_name')
                //             ->createOptionForm([
                //                 Forms\Components\TextInput::make('first_name')
                //                     ->required()
                //                     ->maxLength(255),
                //                 Forms\Components\TextInput::make('last_name')
                //                     ->required()
                //                     ->maxLength(255),
                //                 Forms\Components\TextInput::make('email')
                //                     ->email()
                //                     ->required()
                //                     ->maxLength(255),
                //                 Forms\Components\TextInput::make('country')
                //                     ->required()
                //                     ->maxLength(255),
                //                 Forms\Components\TextInput::make('phone')
                //                     ->tel()
                //                     ->required()
                //                     ->maxLength(255),
                //             ])
                //             // ->live()
                //             ->label('Choose Guest')
                //             //->dehydrated()
                //             ->options(Guest::pluck('full_name', 'id'))
                //             ->searchable()
                //             ->preload()
                //             ->required(),
                //         Forms\Components\TextInput::make('amount_paid')
                //             ->prefix('$')
                //             // ->required()
                //             ->numeric(),
                //         Forms\Components\DatePicker::make('payment_date'),
                //         //   ->required()
                //         //->maxDate(now()),

                //         Forms\Components\Select::make('payment_method')
                //             ->options([
                //                 'cash' => 'Cash',
                //                 'transfer' => 'Transfer',
                //                 'banktransfer' => 'Bank Transfer',
                //             ]),
                //         Forms\Components\FileUpload::make('payment_document_image'),

                //     ])->columns(2),


                // Forms\Components\Section::make('Tour Driver')
                //     //  ->description('Add Tour Related Details')
                //     ->collapsible()
                //     ->schema([
                //         Forms\Components\Select::make('drivers')
                //             ->label('Driver')
                //             ->relationship(name: 'drivers', titleAttribute: 'full_name')
                //             ->searchable()
                //             ->preload()
                //             ->required()
                //             ->createOptionForm([
                //                 Forms\Components\TextInput::make('first_name')
                //                     ->required()
                //                     ->maxLength(255),
                //                 Forms\Components\TextInput::make('last_name')
                //                     ->required()
                //                     ->maxLength(255),
                //                 Forms\Components\TextInput::make('email')
                //                     ->label('Email address')
                //                     ->email()
                //                     ->required()
                //                     ->maxLength(255),
                //                 Forms\Components\TextInput::make('phone01')
                //                     ->label('Phone number #1')
                //                     ->tel()
                //                     ->required()
                //                     ->maxLength(255),
                //                 Forms\Components\TextInput::make('phone02')
                //                     ->label('Phone number #2')
                //                     ->tel()
                //                     ->maxLength(255),
                //                 Forms\Components\Select::make('fuel_type')
                //                     ->options([
                //                         'propane' => 'Propane',
                //                         'methane' => 'Methane',
                //                         'benzin' => 'Benzin',
                //                     ])
                //                     ->required(),
                //                 Forms\Components\FileUpload::make('driver_image')
                //                     ->image()
                //                     ->required(),
                //                 Forms\Components\Textarea::make('extra_details')
                //                     ->label('Extra Details, comments'),
                //                 Forms\Components\TextInput::make('address_city')
                //                     ->label('Where the driver From')
                //                     ->required()
                //                     ->maxLength(255),
                //             ])
                //             ->createOptionUsing(function (array $data) {
                //                 // Here we define how to save the new driver
                //                 return Driver::create($data)->id;
                //             }),


                //         // ->live()

                //         Forms\Components\TextInput::make('amount_paid')
                //             ->prefix('$')
                //             // ->required()
                //             ->numeric(),
                //         Forms\Components\DatePicker::make('payment_date'),
                //         //   ->required()
                //         //->maxDate(now()),make a 
                //         Forms\Components\FileUpload::make('payment_document_image'),
                //         Forms\Components\Select::make('payment_method')
                //             ->options([
                //                 'cash' => 'Cash',
                //                 'transfer' => 'Transfer',
                //                 'banktransfer' => 'Bank Transfer',
                //             ]),

                //     ])->columns(2),


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
                Tables\Columns\TextColumn::make('tour.title'),
                Tables\Columns\TextColumn::make('guest.full_name'),
                Tables\Columns\TextColumn::make('special_request'),
                Tables\Columns\TextColumn::make('guide.full_name'),
                Tables\Columns\TextColumn::make('driver.full_name'),
                // Tables\Columns\TextColumn::make('tourRepeaterDrivers.amount_paid'),
                // Tables\Columns\TextColumn::make('driver.full_name'),
                // Tables\Columns\TextColumn::make('guide.full_name'),

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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSoldTours::route('/'),
            'create' => Pages\CreateSoldTour::route('/create'),
            'edit' => Pages\EditSoldTour::route('/{record}/edit'),
        ];
    }
}
