<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TurfirmaResource\Pages;
use App\Filament\Resources\TurfirmaResource\RelationManagers;
use App\Models\Turfirma;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TurfirmaResource extends Resource
{
    protected static ?string $model = Turfirma::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Hotel Related';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('official_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('address_street')
                    ->required(),
                    //->maxLength(255),
                Forms\Components\TextInput::make('address_city')
                    ->required(),
                   // ->maxLength(255),
                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required(),
                    //->maxLength(255),
                Forms\Components\TextInput::make('inn')
                    ->required(),
                    //->numeric(),
                Forms\Components\TextInput::make('account_number')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('bank_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('bank_mfo')
                    ->required()
                    ->numeric(),
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
                Tables\Columns\TextColumn::make('official_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address_street')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address_city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('inn')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('account_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('bank_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('bank_mfo')
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
            'index' => Pages\ListTurfirmas::route('/'),
            'create' => Pages\CreateTurfirma::route('/create'),
            'edit' => Pages\EditTurfirma::route('/{record}/edit'),
        ];
    }
}
