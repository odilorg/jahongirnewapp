<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Rating;
use App\Models\Booking;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\CheckboxList;
use App\Filament\Resources\RatingResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\RatingResource\RelationManagers;

class RatingResource extends Resource
{
    protected static ?string $model = Rating::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Driver and Guide Details';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                
                Forms\Components\Select::make('review_score')
    ->label('Review Score')
    ->required()
    ->options([
        1 => '1 - Poor',
        2 => '2 - Fair',
        3 => '3 - Good',
        4 => '4 - Very Good',
        5 => '5 - Excellent',
    ])
    ->native(false),
               Textarea::make('comments')
    ->required()
    ->maxLength(65535)
    ->columnSpanFull(),

Select::make('driver_id')
    ->label('Driver')
    ->relationship('driver', 'full_name')
    ->live()
    ->afterStateUpdated(fn ($set) => $set('guide_id', null)),

Select::make('guide_id')
    ->label('Guide')
    ->relationship('guide', 'full_name')
    ->live()
    ->afterStateUpdated(fn ($set) => $set('driver_id', null)),

Select::make('booking_id')
    ->label('Booking')
    ->options(function (Get $get) {
        $driverId = $get('driver_id');
        $guideId = $get('guide_id');

        if ($driverId) {
            return Booking::where('driver_id', $driverId)
                ->with('tour')
                ->get()
                ->pluck('tour.title', 'id');
        }

        if ($guideId) {
            return Booking::where('guide_id', $guideId)
                ->with('tour')
                ->get()
                ->pluck('tour.title', 'id');
        }

        return [];
    })
    ->live()
    ->required(fn (Get $get) => filled($get('driver_id')) || filled($get('guide_id')))
    ->visible(fn (Get $get) => filled($get('driver_id')) || filled($get('guide_id'))),

CheckboxList::make('tags')
    ->label('Feedback Tags')
    ->relationship('tags', 'name')
    ->columns(2)
    ->helperText('Select all that apply — both good and bad behaviors')
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('review_score')
        ->label('Score')
        ->sortable()
        ->alignCenter(),

    TextColumn::make('comments')
        ->label('Comment')
        ->limit(50)
        ->tooltip(fn ($record) => $record->comments),

    TextColumn::make('tags.name')
        ->label('Tags')
        ->badge()
        ->separator(',')
        ->searchable(),

    TextColumn::make('driver.full_name')
        ->label('Driver')
        ->toggleable()
        ->searchable(),

    TextColumn::make('guide.full_name')
        ->label('Guide')
        ->toggleable()
        ->searchable(),

    TextColumn::make('booking.group_name')
        ->label('Booking ID')
        ->toggleable(),

    TextColumn::make('created_at')
        ->label('Rated On')
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
            'index' => Pages\ListRatings::route('/'),
            'create' => Pages\CreateRating::route('/create'),
            'edit' => Pages\EditRating::route('/{record}/edit'),
        ];
    }
}
