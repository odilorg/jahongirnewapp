<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Support\RawJs;
use App\Models\TerminalCheck;
use Filament\Resources\Resource;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TerminalCheckResource\Pages;
use App\Filament\Resources\TerminalCheckResource\RelationManagers;

class TerminalCheckResource extends Resource
{
    protected static ?string $model = TerminalCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            Forms\Components\TextInput::make('amount')
            ->prefix('UZS')
            ->mask(RawJs::make('$money($input)'))
            ->stripCharacters(',')
            ->numeric(),
           
                
            Forms\Components\DatePicker::make('check_date')
                ->required()
                ->native(false)
                ->maxDate(now()),
            Forms\Components\Select::make('card_type')
                ->options([
                    'Humo OK' => 'Humo OK',
                    'Humo YTT' => 'Humo YTT',
                    'Uzcard OK' => 'Uzcard OK',
                    'Uzcard YTT' => 'Uzcard YTT',
                ])
                
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('check_date')
                ->sortable(),
                Tables\Columns\TextColumn::make('card_type')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Humo OK' => 'info',
                    'Humo YTT' => 'warning',
                    'Uzcard OK' => 'success',
                    'Uzcard YTT' => 'danger',
                }),
                Tables\Columns\TextColumn::make('amount'),

            ])
            ->filters([
                Tables\Filters\SelectFilter::make('card_type')
                ->options([
                    'Humo OK' => 'Humo OK',
                    'Humo YTT' => 'Humo YTT',
                    'Uzcard OK' => 'Uzcard OK',
                    'Uzcard YTT' => 'Uzcard YTT',
                ])
                ], layout: FiltersLayout::AboveContent) 
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
            'index' => Pages\ListTerminalChecks::route('/'),
            'create' => Pages\CreateTerminalCheck::route('/create'),
            'edit' => Pages\EditTerminalCheck::route('/{record}/edit'),
        ];
    }
}
