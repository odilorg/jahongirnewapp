<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccommodationResource\RelationManagers;

use App\Models\AccommodationRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RatesRelationManager extends RelationManager
{
    protected static string $relationship = 'rates';

    protected static ?string $title = 'Cost Rates';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('rate_type')
                ->options(AccommodationRate::TYPES)
                ->required()
                ->native(false)
                ->default('per_person'),
            Forms\Components\TextInput::make('room_type')
                ->placeholder('yurt, single, double, etc.')
                ->maxLength(50),
            Forms\Components\TextInput::make('label')
                ->required()
                ->maxLength(100)
                ->placeholder('1 person yurt, Double room'),
            Forms\Components\TextInput::make('min_occupancy')
                ->numeric()
                ->required()
                ->default(1)
                ->minValue(1),
            Forms\Components\TextInput::make('max_occupancy')
                ->numeric()
                ->nullable()
                ->helperText('Leave empty for "N and above"'),
            Forms\Components\TextInput::make('cost_usd')
                ->label('Cost (USD)')
                ->prefix('$')
                ->required()
                ->numeric()
                ->step('0.01'),
            Forms\Components\TextInput::make('includes')
                ->placeholder('dinner + breakfast')
                ->maxLength(255),
            Forms\Components\Toggle::make('is_active')
                ->default(true),
            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('rate_type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'per_person' ? 'success' : 'info'),
                Tables\Columns\TextColumn::make('label')
                    ->searchable(),
                Tables\Columns\TextColumn::make('room_type'),
                Tables\Columns\TextColumn::make('min_occupancy')
                    ->label('Min'),
                Tables\Columns\TextColumn::make('max_occupancy')
                    ->label('Max')
                    ->default('∞'),
                Tables\Columns\TextColumn::make('cost_usd')
                    ->label('Cost')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('includes')
                    ->limit(30),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
