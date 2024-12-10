<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use App\Models\ScheduledMessage;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ScheduledMessageResource\Pages;
use App\Filament\Resources\ScheduledMessageResource\RelationManagers;
use App\Models\Chatid;

class ScheduledMessageResource extends Resource
{
    protected static ?string $model = ScheduledMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Scheduled Messages';

    public static function form(Form $form): Form
    {

      //  dd(env('JAHONGIRCLEANINGBOT'));

        return $form
            ->schema([
                
                Forms\Components\Textarea::make('message')
                    ->required()
                    ->label('Message'),
                Forms\Components\DateTimePicker::make('scheduled_at')
                    ->required()
                    ->label('Schedule Date and Time'),
                Forms\Components\Select::make('chat_id')
                    ->label('Chat')
                    ->options(\App\Models\Chat::pluck('name', 'id'))
                    ->required(),
                Forms\Components\Select::make('frequency')
                    ->label('Frequency')
                    ->options([
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly',
                        'yearly' => 'Yearly',
                    ])
                    ->default('daily')
                    ->required()
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('message')
                    ->limit(30),
                Tables\Columns\TextColumn::make('chat.name')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'sent' => 'success',
                        'failed' => 'danger'
                    }),
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

    public static function canViewAny(): bool
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        
        return $user && $user->hasRole('super_admin');
    }
}
