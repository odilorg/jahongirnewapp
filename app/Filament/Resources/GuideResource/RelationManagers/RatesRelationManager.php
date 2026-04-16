<?php

declare(strict_types=1);

namespace App\Filament\Resources\GuideResource\RelationManagers;

use App\Models\GuideRate;
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
            Forms\Components\TextInput::make('label')
                ->required()
                ->maxLength(100)
                ->placeholder('Shahrisabz day trip, City tour, etc.'),
            Forms\Components\Select::make('rate_type')
                ->options(GuideRate::TYPES)
                ->required()
                ->native(false)
                ->default('per_trip'),
            Forms\Components\TextInput::make('cost_usd')
                ->label('Cost (USD)')
                ->prefix('$')
                ->required()
                ->numeric()
                ->step('0.01'),
            Forms\Components\Textarea::make('notes')
                ->placeholder('Languages, specialization, etc.')
                ->rows(2),
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
                Tables\Columns\TextColumn::make('label')->searchable(),
                Tables\Columns\TextColumn::make('rate_type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'per_trip' ? 'success' : 'info'),
                Tables\Columns\TextColumn::make('cost_usd')->label('Cost')->money('USD'),
                Tables\Columns\TextColumn::make('notes')->limit(40),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
