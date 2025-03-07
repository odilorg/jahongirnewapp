<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScheduledMessageResource\Pages;
use App\Models\ScheduledMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use App\Models\Chat;

class ScheduledMessageResource extends Resource
{
    protected static ?string $model = ScheduledMessage::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Scheduled Messages';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('message')
                    ->label('Message')
                    ->required(),
                Forms\Components\DateTimePicker::make('scheduled_at')
                    ->label('Schedule Date & Time')
                    ->required(),
                // Use a relationship-based select if possible:
                Forms\Components\Select::make('chat_id')
                    ->label('Chat')
                    ->relationship('chat', 'name') // or ->options(Chat::pluck('name', 'id'))
                    ->required(),
                Forms\Components\Select::make('frequency')
                    ->label('Frequency')
                    ->options([
                        ScheduledMessage::FREQUENCY_NONE     => 'None (One-time)',
                        ScheduledMessage::FREQUENCY_DAILY    => 'Daily',
                        ScheduledMessage::FREQUENCY_WEEKLY   => 'Weekly',
                        ScheduledMessage::FREQUENCY_MONTHLY  => 'Monthly',
                        ScheduledMessage::FREQUENCY_YEARLY   => 'Yearly',
                    ])
                    ->default(ScheduledMessage::FREQUENCY_NONE)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('message')->limit(30),
                TextColumn::make('chat.name')
                    ->label('Chat Name')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'   => 'gray',
                        'processing'=> 'warning',
                        'sent'      => 'success',
                        'failed'    => 'danger',
                        default     => 'secondary',
                    }),
                TextColumn::make('scheduled_at')->dateTime(),
                TextColumn::make('frequency')->badge(),
            ])
            ->filters([
                // Define Filament filters if needed
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListScheduledMessages::route('/'),
            'create' => Pages\CreateScheduledMessage::route('/create'),
            'edit'   => Pages\EditScheduledMessage::route('/{record}/edit'),
        ];
    }

    // public static function canViewAny(): bool
    // {
    //     // Example: only super_admin can see
    //     $user = auth()->user();
    //     return $user && $user->hasRole('super_admin');
    // }
}
