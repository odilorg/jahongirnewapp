<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Meter;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Pages\ViewUtilityUsage;
use App\Models\UtilityUsage;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\UtilityUsageResource\Pages;
use App\Filament\Resources\UtilityUsageResource\RelationManagers;

class UtilityUsageResource extends Resource
{
    protected static ?string $model = UtilityUsage::class;
    protected static ?string $navigationGroup = 'Hotel Management';
    protected static ?string $navigationParentItem = 'Коммунальные услуги';
    protected static ?string $navigationLabel = 'Показания';
     protected static ?string $modelLabel = 'Показания';
     protected static ?string $pluralModelLabel = 'Показания';


    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static string $view = 'filament.resources.utility-usages.pages.view-utility-usage.blade';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('hotel_id')
                    ->relationship('hotel', 'name')
                    ->required()
                    ->reactive(), // Make reactive to update the meter field dynamically
                Forms\Components\Select::make('utility_id')
                    ->relationship('utility', 'name')
                    ->required()
                    ->reactive(), // Make reactive to update the meter field dynamically
                Forms\Components\Select::make('meter_id')
                    ->label('Meter')
                    ->required()
                    ->options(function (callable $get) {
                        $hotelId = $get('hotel_id');
                        $utilityId = $get('utility_id');

                        if ($hotelId && $utilityId) {
                            return Meter::where('hotel_id', $hotelId)
                                ->where('utility_id', $utilityId)
                                ->pluck('meter_serial_number', 'id');
                        }

                        return []; // Return an empty array if no hotel or utility is selected
                    })
                    ->reactive(),
                Forms\Components\DatePicker::make('usage_date')
                    ->default(now())
                    ->required(),
                Forms\Components\TextInput::make('meter_latest')
                    ->required()
                    ->numeric()
                    ->live(onBlur:true)
                    ->gte('meter_previous')
                    ->afterStateUpdated(function(get $get, Set $set){
                        Self::calculateMeterDif($get, $set);
                    }),
                   // ->rule('gt:meter_previous'), // Add validation to ensure meter_latest is greater than meter_previous
                Forms\Components\TextInput::make('meter_previous')
                    ->required()
                    ->live(onBlur:true)
                    ->numeric()
                    ->afterStateUpdated(function(get $get, Set $set){
                        Self::calculateMeterDif($get, $set);
                    }),
                Forms\Components\TextInput::make('meter_difference')
              //  ->hidden()
                ->required()
                    ->numeric()
                    ->readOnly(),
                Forms\Components\FileUpload::make('meter_image')
                    ->image(),
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
                    Tables\Columns\TextColumn::make('hotel.name')
                   // ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('utility.name')
                   // ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('meter.meter_serial_number')
                   // ->numeric()
                    ->sortable(),
              
                Tables\Columns\TextColumn::make('usage_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('meter_latest')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('meter_previous')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('meter_difference')
                    ->numeric()
                    ->sortable(),
                
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUtilityUsages::route('/'),
            'create' => Pages\CreateUtilityUsage::route('/create'),
            'edit' => Pages\EditUtilityUsage::route('/{record}/edit'),
           'view' => Pages\ViewUtilityUsage::route('/{record}'),
           'print' => Pages\PrintUtilityUsage::route('/{record}/print'),
           
        ];
    }

    public static function calculateMeterDif(Get $get, Set $set): void {
        $set('meter_difference', $get('meter_latest')-$get('meter_previous'));
    }

     
// public static function infolist(Infolist $infolist): Infolist
// {
//     return $infolist
//         ->schema([
//             TextEntry::make('hotel.name')


//         ]);
// }
}

