<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TelegramBotConversationResource extends Resource
{
    protected static ?string $model = \App\Models\TelegramConversation::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    
    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([])
            ->filters([])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [];
    }
}
