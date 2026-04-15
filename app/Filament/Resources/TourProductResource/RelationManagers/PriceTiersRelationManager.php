<?php

declare(strict_types=1);

namespace App\Filament\Resources\TourProductResource\RelationManagers;

use App\Models\TourProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Price tiers on a tour product — scoped by direction + type + group size.
 *
 * Direction is nullable: a NULL direction means "applies to all directions
 * of this product" (a global tier). A specific direction takes precedence
 * over a global tier for that direction when pricing is resolved.
 *
 * Type is private or group — a tour product can carry both sets of tiers
 * simultaneously and TourProduct::priceFor() picks the right one at
 * quote time based on the inquiry's type.
 *
 * Saving a tier triggers the parent's recalculateStartingPrice() so the
 * cached "starting from" value on the product row stays accurate.
 */
class PriceTiersRelationManager extends RelationManager
{
    protected static string $relationship = 'priceTiers';

    protected static ?string $title = 'Price tiers';

    protected static ?string $recordTitleAttribute = 'group_size';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('tour_product_direction_id')
                ->label('Direction')
                ->options(function () {
                    /** @var TourProduct $tour */
                    $tour = $this->getOwnerRecord();

                    return $tour->directions()
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn ($d) => [$d->id => "{$d->name} ({$d->code})"]);
                })
                ->placeholder('All directions (global tier)')
                ->helperText('Leave blank for a price that applies to every direction.')
                ->native(false),

            Forms\Components\Select::make('tour_type')
                ->label('Type')
                ->options([
                    TourProduct::TYPE_PRIVATE => 'Private',
                    TourProduct::TYPE_GROUP   => 'Group',
                ])
                ->default(TourProduct::TYPE_PRIVATE)
                ->required()
                ->native(false),

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
                Tables\Columns\TextColumn::make('direction.code')
                    ->label('Direction')
                    ->placeholder('all')
                    ->fontFamily('mono')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tour_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => $state === TourProduct::TYPE_GROUP ? 'info' : 'gray'),
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
                    ->limit(60)
                    ->toggleable(),
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
