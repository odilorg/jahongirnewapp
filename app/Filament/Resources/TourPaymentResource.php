<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TourPaymentResource\Pages;
use App\Filament\Resources\TourPaymentResource\RelationManagers;
use App\Models\TourPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TourPaymentResource extends Resource
{
    protected static ?string $model = TourPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
               
                Forms\Components\Select::make('tour_booking_id')
                    ->relationship(name: 'tour_booking', titleAttribute: 'group_number')
                    ->preload()
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('amount_paid')
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
                Tables\Columns\TextColumn::make('tour_booking_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_paid')
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
            'index' => Pages\ListTourPayments::route('/'),
            'create' => Pages\CreateTourPayment::route('/create'),
            'edit' => Pages\EditTourPayment::route('/{record}/edit'),
        ];
    }
}
