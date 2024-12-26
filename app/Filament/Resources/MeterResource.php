<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MeterResource\Pages;
use App\Filament\Resources\MeterResource\RelationManagers;
use App\Models\Meter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MeterResource extends Resource
{
    protected static ?string $model = Meter::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('hotel_id')
                    ->relationship('hotel', 'name')
                    ->preload()
                    ->required(),
                    Forms\Components\Select::make('utility_id')
                    ->relationship('utility', 'name')
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('meter_serial_number')
                    ->required()
                    ->maxLength(255),
               
                    
                Forms\Components\DatePicker::make('sertificate_expiration_date')
                    ->required(),
                Forms\Components\FileUpload::make('sertificate_image')
                    ->image(),
                Forms\Components\TextInput::make('contract_number')
                    ->required()
                    ->maxLength(255),
                Forms\Components\DatePicker::make('contract_date')
                    ->required(),
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
                Tables\Columns\TextColumn::make('meter_serial_number')
                    ->searchable(),
               
                Tables\Columns\TextColumn::make('sertificate_expiration_date')
                    ->date()
                    ->sortable(),
              //  Tables\Columns\ImageColumn::make('sertificate_image'),
                Tables\Columns\TextColumn::make('contract_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('contract_date')
                    ->date()
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
            'index' => Pages\ListMeters::route('/'),
            'create' => Pages\CreateMeter::route('/create'),
            'edit' => Pages\EditMeter::route('/{record}/edit'),
        ];
    }
}