<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\Room;
use Filament\Tables;
use App\Models\Amenity;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\CheckboxList;
use App\Filament\Resources\RoomResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\RoomResource\RelationManagers;

class RoomResource extends Resource
{
    protected static ?string $model = Room::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Hotel Management';

    protected static ?string $navigationParentItem = 'Hotels';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->required()
                    ->maxLength(65535)
                    ->columnSpanFull(),
               
                Forms\Components\TextInput::make('room_number')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('room_floor')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('notes')
                    //->required()
                    ->maxLength(255),
                  
                    //->maxValue(42949672.95),    
                // CheckboxList::make('amenities')
                //     ->relationship()
                //     ->options(Amenity::all()->pluck('name', 'id'))
                CheckboxList::make('amenities')
                    ->relationship(titleAttribute: 'name')
                    ->bulkToggleable(),
                Select::make('hotel_id')
                    ->relationship(name: 'hotel', titleAttribute: 'name'),
               
                    Select::make('room_type_id') // Use the foreign key field name
    ->relationship('roomType', 'name') // Reference the relationship and display field
    ->label('Room Type')
    ->required()

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
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('room_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('room_floor')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('notes')
                    ->searchable(),
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
            'index' => Pages\ListRooms::route('/'),
            'create' => Pages\CreateRoom::route('/create'),
            'edit' => Pages\EditRoom::route('/{record}/edit'),
        ];
    }
}
