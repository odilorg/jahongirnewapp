<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScheduledMessageResource\Pages;
use App\Filament\Resources\ScheduledMessageResource\RelationManagers;
use App\Models\ScheduledMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ScheduledMessageResource extends Resource
{
    protected static ?string $model = ScheduledMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('message')
                ->required()
                ->label('Message'),
            Forms\Components\DateTimePicker::make('scheduled_at')
                ->required()
                ->label('Schedule Date and Time'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('message'),
                Tables\Columns\TextColumn::make('scheduled_at')->dateTime(),
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
            'index' => Pages\ListScheduledMessages::route('/'),
            'create' => Pages\CreateScheduledMessage::route('/create'),
            'edit' => Pages\EditScheduledMessage::route('/{record}/edit'),
        ];
    }
}
