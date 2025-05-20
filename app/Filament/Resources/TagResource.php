<?php

namespace App\Filament\Resources;

use App\Models\Tag;
use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\TagResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TagResource\RelationManagers;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                 TextInput::make('name')
            ->required()
            ->live(onBlur: true)
            ->afterStateUpdated(function (Set $set, Get $get, ?string $state, ?string $old) {
                // Only update key if user hasn't manually edited it
                $currentKey = $get('key');
                $expectedOldKey = Str::slug($old ?? '');

                if ($currentKey === $expectedOldKey || blank($currentKey)) {
                    $set('key', Str::slug($state ?? ''));
                }
            }),

        TextInput::make('key')
            ->required()
            ->unique(ignoreRecord: true),

        Forms\Components\Select::make('type')
            ->options([
                'positive' => 'Positive',
                'negative' => 'Negative',
                'neutral'  => 'Neutral',
            ])
            ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                 TextColumn::make('name')->searchable()->sortable(),
        TextColumn::make('type')->badge()->sortable(),
        TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
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
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }
}
