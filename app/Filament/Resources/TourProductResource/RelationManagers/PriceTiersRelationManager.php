<?php

declare(strict_types=1);

namespace App\Filament\Resources\TourProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Inline price tier editor on the Tour Product edit page.
 *
 * Operators add one row per group size with the per-person USD price.
 * Saving a tier triggers TourPriceTier::booted() → recalculates the
 * parent's cached starting_from_usd so list views stay accurate.
 */
class PriceTiersRelationManager extends RelationManager
{
    protected static string $relationship = 'priceTiers';

    protected static ?string $title = 'Price tiers';

    protected static ?string $recordTitleAttribute = 'group_size';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('group_size')
                ->label('Group size')
                ->numeric()
                ->minValue(1)
                ->required(),

            Forms\Components\TextInput::make('price_per_person_usd')
                ->label('Price / person (USD)')
                ->numeric()
                ->step('0.01')
                ->prefix('$')
                ->required(),

            Forms\Components\TextInput::make('notes')
                ->maxLength(255)
                ->columnSpanFull()
                ->placeholder('e.g. includes camel ride, dinner + breakfast'),

            Forms\Components\Toggle::make('is_active')
                ->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('group_size')
            ->columns([
                Tables\Columns\TextColumn::make('group_size')
                    ->label('Group')
                    ->alignCenter()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_per_person_usd')
                    ->label('Per person')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('USD')
                    ->state(fn ($record): float => $record->totalForGroup()),
                Tables\Columns\TextColumn::make('notes')
                    ->wrap()
                    ->limit(60),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('+ Add tier'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
