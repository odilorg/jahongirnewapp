<?php

declare(strict_types=1);

namespace App\Filament\Resources\TourProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Route variants of a tour product. Yurt Camp's three variants are
 * sam-bukhara / sam-sam / bukhara-sam. Each can have its own pricing
 * via the sibling PriceTiersRelationManager.
 */
class DirectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'directions';

    protected static ?string $title = 'Directions (route variants)';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(64)
                ->placeholder('sam-bukhara')
                ->helperText('Short, URL-safe, unique within this tour. Used by pricing rules.'),

            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(191)
                ->placeholder('Samarkand → Bukhara'),

            Forms\Components\TextInput::make('start_city')->maxLength(64),
            Forms\Components\TextInput::make('end_city')->maxLength(64),

            Forms\Components\Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Toggle::make('is_active')->default(true),

            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->alignCenter(),
                Tables\Columns\TextColumn::make('code')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('start_city')->label('From')->toggleable(),
                Tables\Columns\TextColumn::make('end_city')->label('To')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('+ Add direction'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
