<?php

namespace App\Filament\Resources;

use App\Models\Car;
use Filament\Forms;
use App\Models\Tour;
use Filament\Tables;
use App\Models\Driver;
use App\Models\SoldTour;
use Filament\Forms\Form;
use App\Models\CarDriver;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Fieldset;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Infolists\Components\ImageEntry;
use App\Filament\Resources\DriverResource\Pages;
use Filament\Infolists\Components\RepeatableEntry;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\DriverResource\RelationManagers;
use App\Filament\Resources\DriverResource\RelationManagers\CarRelationManager;
use App\Filament\Resources\DriverResource\RelationManagers\CarsRelationManager;
use App\Filament\Resources\DriverResource\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\DriverResource\RelationManagers\TourExpensesRelationManager;
use App\Filament\Resources\DriverResource\RelationManagers\SupplierPaymentsRelationManager;

//use App\Filament\Resources\TourRepeaterDriversRelationManagerResource\RelationManagers\SoldToursRelationManager;


class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationGroup = 'Tour Operations';
    protected static ?int    $navigationSort  = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\Section::make('Driver Personal Info')
                    ->description('Add information about Driver')
                    ->collapsible()
                    ->schema([
                        // Forms\Components\Select::make('car_id')
                        // ->required()
                        // //  ->maxLength(255)
                        // ->searchable()
                        // ->preload()
                        // ->relationship('cars', 'plate_number'),
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
                        Forms\Components\TextInput::make('telegram_chat_id')
                            ->label('Telegram')
                            ->placeholder('@username or chat ID')
                            ->helperText('Used for automated tour dispatch DMs')
                            ->maxLength(100),
                        Forms\Components\Select::make('fuel_type')
                            ->options([
                                'propane' => 'Propane',
                                'methane' => 'Methane',
                                'benzin' => 'Benzin',
                            ]),

                        Forms\Components\FileUpload::make('driver_image')
                            ->image(),
                        //  ->required(),
                        Forms\Components\Textarea::make('extra_details')
                            ->label('Extra Details, comments'),
                        Forms\Components\TextInput::make('address_city')
                            ->label('Where the driver From')
                            ->required()
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('💳 Платежные реквизиты (P2P)')
                    ->description('Карта водителя для прямых переводов после тура. Хранится только номер — без срока действия и CVV.')
                    ->collapsible()
                    ->collapsed(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Forms\Components\TextInput::make('card_number')
                            ->label('Номер карты')
                            ->placeholder('8600 1234 5678 9012')
                            ->mask('9999 9999 9999 9999')
                            ->stripCharacters([' ', '-'])
                            ->rule('digits:16')
                            ->helperText('16 цифр. Пробелы убираются автоматически.')
                            ->autocomplete('off')
                            ->maxLength(19),
                        Forms\Components\Select::make('card_bank')
                            ->label('Банк / тип карты')
                            ->options([
                                'Humo'   => 'Humo',
                                'Uzcard' => 'Uzcard',
                                'Visa'   => 'Visa',
                                'Mastercard' => 'Mastercard',
                            ])
                            ->native(false)
                            ->searchable(),
                        Forms\Components\TextInput::make('card_holder_name')
                            ->label('Владелец карты')
                            ->placeholder('JAMSHID KARIMOV')
                            ->helperText('Если карта оформлена не на водителя (например, на жену) — впишите реальное имя владельца.')
                            ->maxLength(100),
                    ])->columns(2),

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
                Tables\Columns\TextColumn::make('extra_details')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('address_city')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('first_name')
                    ->weight('bold')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->weight('bold')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone01')
                    ->fontFamily('mono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Small)
                    ->color('gray')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('phone02')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('fuel_type')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('cars.model')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_amount_paid')
                    ->label('Total Amount Paid')
                    ->formatStateUsing(fn($state) => number_format($state, 2)) // Convert to dollars and format
                    ->money('USD', divideBy: 100)
                    ->getStateUsing(fn($record) => $record->total_amount_paid), // Use the accessor method

                // TextColumn::make('soldTours.tour.title')
                //     ->label('Tour Title')
                //     ->formatStateUsing(function ($state, $record) {
                //         // Get the first associated SoldTour
                //         $soldTour = $record->soldTours->first();

                //         // Check if soldTour and its tour are available
                //         return $soldTour ? $soldTour->tour->title : 'Unknown Title';
                //     })
                //     ->sortable()
                //     ->searchable(),
                Tables\Columns\ImageColumn::make('driver_image')
                    ->circular(),

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
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('New driver')->icon('heroicon-o-plus'),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->striped();
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist


            ->schema([

                Section::make('Driver Info')
                    // ->description('Prevent abuse by limiting the number of requests per period')
                    ->schema([
                        TextEntry::make('full_name'),
                        TextEntry::make('email'),
                        TextEntry::make('phone01'),
                        TextEntry::make('phone02'),
                        TextEntry::make('fuel_type'),
                        TextEntry::make('address_city'),
                        TextEntry::make('extra_details'),
                        ImageEntry::make('driver_image')
                            ->circular()
                    ])->columns(2),






                Section::make('💳 Платежные реквизиты (P2P)')
                    ->schema([
                        TextEntry::make('card_number_formatted')
                            ->label('Номер карты')
                            ->placeholder('—')
                            ->fontFamily('mono')
                            ->copyable()
                            ->copyMessage('Скопировано ✓')
                            ->copyMessageDuration(1500)
                            ->copyableState(fn ($record) => $record?->card_number),
                        TextEntry::make('card_bank')
                            ->label('Банк')
                            ->placeholder('—'),
                        TextEntry::make('card_holder_name')
                            ->label('Владелец')
                            ->placeholder('—')
                            ->copyable()
                            ->copyMessage('Скопировано ✓')
                            ->copyMessageDuration(1500),
                        TextEntry::make('card_updated_at')
                            ->label('Реквизиты обновлены')
                            ->dateTime()
                            ->placeholder('—'),
                    ])->columns(2),

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

                        RepeatableEntry::make('cars')
                            ->schema([
                                TextEntry::make('model'),
                                TextEntry::make('number_seats'),
                                TextEntry::make('number_luggage'),
                                ImageEntry::make('image'),
                                TextEntry::make('pivot.car_plate') // Accessing car_plate from the pivot table
                                    ->label('Car Plate')
                                    ->getStateUsing(fn($record) => $record->pivot?->car_plate) // Fetch car_plate from the pivot table
                                    ->columnSpan(2),
                            ])
                            ->columns(2)


                    ])->columns(2),




            ]);
    }

    public static function getRelations(): array
    {
        return [
            CarsRelationManager::class,
            BookingsRelationManager::class,
            TourExpensesRelationManager::class,
            DriverResource\RelationManagers\RatesRelationManager::class,
            DriverResource\RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'view' => Pages\ViewDriver::route('/{record}'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
