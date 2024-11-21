<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Zayavka;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\ZayavkaResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ZayavkaResource\RelationManagers;

class ZayavkaResource extends Resource
{
    protected static ?string $model = Zayavka::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Hotel Related';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('turfirma_id')
                    ->relationship(name: 'turfirma', titleAttribute: 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                Forms\Components\DatePicker::make('submitted_date')
                    //->format('d/m/Y')
                    ->native(false)
                    ->closeOnDateSelection()
                    ->required(),
                Select::make('status')
                    ->options([
                        'accepted' => 'Accepted',
                        'no_room_avil' => 'No Rooms',
                        'waiting' => 'Waiting List',
                    ]),
                Forms\Components\TextInput::make('source')
                    ->required()
                    ->maxLength(255),
                Select::make('accepted_by')
                    ->options([
                        'odil' => 'Odil',
                        'zafar' => 'Zafar',
                        'javohir' => 'Javohir',
                        'asror' => 'Asror',
                    ]),
                Forms\Components\TextInput::make('description')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('hotel_id')
                    ->relationship(name: 'hotel', titleAttribute: 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
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
                Tables\Columns\TextColumn::make('turfirma.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('source')
                    ->searchable(),
                Tables\Columns\TextColumn::make('accepted_by')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('hotel.name')
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
            'index' => Pages\ListZayavkas::route('/'),
            'create' => Pages\CreateZayavka::route('/create'),
            'edit' => Pages\EditZayavka::route('/{record}/edit'),
        ];
    }
}
