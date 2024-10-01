<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\Tour;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Support\RawJs;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Resources\TourResource\Pages;
use Filament\Infolists\Components\RepeatableEntry;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TourResource\RelationManagers;

class TourResource extends Resource
{
    protected static ?string $model = Tour::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-americas';

    protected static ?string $navigationGroup = 'Tour Details';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\Section::make('Tour Prices')
                    ->description('Add Tour Prices')
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('tour_duration')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('tour_description')
                            ->required()
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),




                Forms\Components\Section::make('Tour Prices')
                    ->description('Add Tour Prices')
                    ->collapsible()
                    ->schema([
                        Repeater::make(name: 'tourPrices')
                            ->label('Tour Prices')
                            ->relationship()  // Make sure this points to the correct relationship method in the TourBooking model
                            ->schema([

                                // Forms\Components\TextInput::make('tour_id')
                                //     ->required()
                                //     ->maxLength(255),
                                Forms\Components\TextInput::make('number_people')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('tour_price')
                                    ->prefix('$')
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric(),


                            ])->columnSpan(1),
                    ]),

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
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tour_duration')
                    ->searchable(),


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

                Section::make('Tour Info')
                    // ->description('Prevent abuse by limiting the number of requests per period')
                    ->schema([
                        TextEntry::make('title'),
                        TextEntry::make('tour_duration'),
                    ])->columns(2),

                Section::make(heading: 'Tour Prices')
                    // ->description('Prevent abuse by limiting the number of requests per period')
                    ->schema([

                        RepeatableEntry::make('tourPrices')
                            ->schema([
                                TextEntry::make('number_people'),
                                TextEntry::make('tour_price')
                               
                                    //->columnSpan(2),
                            ])->columns(2)
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
            'index' => Pages\ListTours::route('/'),
            'create' => Pages\CreateTour::route('/create'),
            //     'view' => Pages\ViewTour::route('/{record}'),
            'edit' => Pages\EditTour::route('/{record}/edit'),
        ];
    }
}
