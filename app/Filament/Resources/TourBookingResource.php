<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TourBookingResource\Pages;
use App\Filament\Resources\TourBookingResource\RelationManagers;
use App\Models\TourBooking;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TourBookingResource extends Resource
{
    protected static ?string $model = TourBooking::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('tour_id')
                    ->relationship(name: 'tour', titleAttribute: 'title')
                    ->preload()
                    ->searchable()
                    ->required(),
                    
                Forms\Components\Select::make('guest_id')
                        ->relationship(name: 'guest', titleAttribute: 'full_name')
                        ->preload()
                        ->searchable()
                        ->required(),
                Forms\Components\Select::make('driver_id')
                        ->relationship(name: 'driver', titleAttribute: 'full_name')
                        ->preload()
                        ->searchable()
                        ->required(),
                Forms\Components\Select::make('guide_id')
                        ->relationship(name: 'guide', titleAttribute: 'full_name')
                        ->preload()
                        ->searchable()
                        ->required(),
                Forms\Components\TextInput::make('number_of_adults')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('number_of_children')
                    ->required()
                    ->numeric(),
                Forms\Components\Textarea::make('special_requests')
                    ->required()
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('pickup_location')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('dropoff_location')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('group_number')
                    ->required()
                    ->maxLength(255),    
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
                Tables\Columns\TextColumn::make('tour.title')
                   
                    ->sortable(),
                Tables\Columns\TextColumn::make('guest.full_name')
                    
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver.full_name')
                    
                    ->sortable(),
                Tables\Columns\TextColumn::make('guide.full_name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('number_of_adults')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('number_of_children')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pickup_location')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dropoff_location')
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTourBookings::route('/'),
            'create' => Pages\CreateTourBooking::route('/create'),
            'view' => Pages\ViewTourBooking::route('/{record}'),
            'edit' => Pages\EditTourBooking::route('/{record}/edit'),
        ];
    }
}
