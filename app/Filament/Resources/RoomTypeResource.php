<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoomTypeResource\Pages;
use App\Filament\Resources\RoomTypeResource\RelationManagers;
use App\Models\RoomType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use FontLib\Table\Type\name;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RoomTypeResource extends Resource
{
    protected static ?string $model = RoomType::class;

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
                Forms\Components\TextInput::make('descrtiption')
                    ->maxLength(255),
                Forms\Components\TextInput::make('price_as_single')
                    ->required()
                    ->numeric()
                    ->prefix('UZ'), 
                Forms\Components\TextInput::make('price_as_double')
                    ->required()
                    ->numeric()
                    ->prefix('UZ'), 
                Forms\Components\TextInput::make('quantity')
                    ->required()
                    ->numeric(),     
                Forms\Components\TextInput::make('number_of_beds')
                    ->required()
                    ->numeric(), 
                Forms\Components\Select::make('hotel_id')
                     ->relationship('hotel', 'name' )
                    ->required()
                    ->preload()          
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
                Tables\Columns\TextColumn::make('descrtiption')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price_as_single')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_as_double')
                    ->numeric()
                    ->sortable(),
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
            'index' => Pages\ListRoomTypes::route('/'),
            'create' => Pages\CreateRoomType::route('/create'),
            'edit' => Pages\EditRoomType::route('/{record}/edit'),
        ];
    }
}
