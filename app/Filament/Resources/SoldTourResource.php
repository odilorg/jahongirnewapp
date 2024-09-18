<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\Tour;
use Filament\Tables;
use App\Models\Driver;
use App\Models\SoldTour;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\SoldTourResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\SoldTourResource\RelationManagers;
use App\Models\Guide;
use App\Models\SpokenLanguage;

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
                        Forms\Components\Select::make(name: 'tour_id')
                            // ->live()
                            ->label('Choose Tour')
                            //->dehydrated()
                            ->options(Tour::pluck('title', 'id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('pickup_location')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('dropoff_location')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('special_request')
                            //   ->required()
                            ->maxLength(1000),

                    ]),


                Forms\Components\Section::make('Tour Drivers, Guides')
                    //  ->description('Add Tour Related Details')
                    ->collapsible()
                    ->schema([
                        Repeater::make('drivers')
                            ->relationship('tourRepeaterDrivers')
                            ->schema([

                                Forms\Components\Select::make('driver_id')
                                    ->label('Driver')
                                    ->options(Driver::pluck('full_name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->createOptionForm([
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
                                        Forms\Components\Textarea::make('extra_details')
                                            ->label('Extra Details, comments'),
                                        Forms\Components\TextInput::make('address_city')
                                            ->label('Where the driver From')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->createOptionUsing(function (array $data) {
                                        // Here we define how to save the new driver
                                        return Driver::create($data)->id;
                                    }),


                                    // ->live()
                                  
                                Forms\Components\TextInput::make('amount_paid')
                                    ->prefix('$')
                                    // ->required()
                                    ->numeric(),
                                Forms\Components\DatePicker::make('payment_date'),
                                //   ->required()
                                //->maxDate(now()),
                                Forms\Components\FileUpload::make('payment_document_image'),
                                Forms\Components\Select::make('payment_method')
                                    ->options([
                                        'cash' => 'Cash',
                                        'transfer' => 'Transfer',
                                        'banktransfer' => 'Bank Transfer',
                                    ])
                            ]),

                        Repeater::make('guides')
                            ->relationship('tourRepeaterGuides')
                            ->schema([
                                Forms\Components\Select::make('guide_id')
                                    ->label('Guide')
                                    ->options(Guide::pluck('full_name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->createOptionForm([
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
                                            ->tel(),
                                        Forms\Components\Select::make('languages')
                                            ->label('Languages')
                                            ->options(SpokenLanguage::pluck('language', 'id')) // Load languages from SpokenLanguage model
                                            ->multiple() // Allow multiple selections
                                            ->searchable()
                                            ->preload()
                                            ->required(),
                                            Forms\Components\FileUpload::make('guide_image')
                                            ->image()
                                            //->required()

                                           
                                    ])
                                    ->createOptionUsing(function (array $data) {
                                        // First, create the guide
                                        $guide = Guide::create([
                                            'first_name' => $data['first_name'],
                                            'last_name' => $data['last_name'],
                                            'email' => $data['email'],
                                            'phone01' => $data['phone01'],
                                            'phone02' => $data['phone02'],
                                            // Add other guide fields here as necessary
                                        ]);
                        
                                        // If languages were selected, sync them with the guide's languages relationship
                                        if (isset($data['languages'])) {
                                            $guide->languages()->sync($data['languages']); // Sync the pivot table (language_guide)
                                        }
                        
                                        return $guide->id; // Return the guide ID to be used in the form
                                    }),

                                 
                                Forms\Components\TextInput::make('amount_paid')
                                    ->prefix('$')
                                    // ->required()
                                    ->numeric(),
                                Forms\Components\DatePicker::make('payment_date'),
                                //   ->required()
                                //->maxDate(now()),
                                Forms\Components\FileUpload::make('payment_document_image'),
                                Forms\Components\Select::make('payment_method')
                                    ->options([
                                        'cash' => 'Cash',
                                        'transfer' => 'Transfer',
                                        'banktransfer' => 'Bank Transfer',
                                    ])
                            ])


                        //   ->required(),



                    ])->columns(2)
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
                Tables\Columns\TextColumn::make('special_request'),
                // Tables\Columns\TextColumn::make('tourRepeaterDrivers.amount_paid'),
                Tables\Columns\TextColumn::make('tourRepeaterDrivers.driver.first_name'),
                Tables\Columns\TextColumn::make('tourRepeaterGuides.guide.first_name'),

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
