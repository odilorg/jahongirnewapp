<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TerminalCheckResource\Pages;
use App\Filament\Resources\TerminalCheckResource\RelationManagers;
use App\Models\TerminalCheck;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TerminalCheckResource extends Resource
{
    protected static ?string $model = TerminalCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
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
            'index' => Pages\ListTerminalChecks::route('/'),
            'create' => Pages\CreateTerminalCheck::route('/create'),
            'edit' => Pages\EditTerminalCheck::route('/{record}/edit'),
        ];
    }
}
