<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\TelegramServiceKey;
use App\Services\Telegram\LegacyConfigBotAdapter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TelegramServiceKeyResource extends Resource
{
    protected static ?string $model = TelegramServiceKey::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Telegram';

    protected static ?string $navigationLabel = 'API Keys';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasRole('super_admin');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('App / Service Name')
                    ->required()
                    ->placeholder('e.g. orient-travel-website')
                    ->maxLength(100),

                Forms\Components\CheckboxList::make('allowed_slugs')
                    ->label('Allowed Bots')
                    ->options(fn () => \App\Models\TelegramBot::pluck('name', 'slug')->toArray())
                    ->helperText('Leave empty to allow all bots')
                    ->columns(2),

                Forms\Components\CheckboxList::make('allowed_actions')
                    ->label('Allowed Actions')
                    ->options([
                        'send-message' => 'Send Message',
                        'send-photo' => 'Send Photo',
                        'get-me' => 'Get Me (test connection)',
                        'webhook-info' => 'Webhook Info',
                        'set-webhook' => 'Set Webhook',
                        'delete-webhook' => 'Delete Webhook',
                    ])
                    ->helperText('Leave empty to allow all actions')
                    ->columns(2),

                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('Expires At (optional)')
                    ->helperText('Leave empty for no expiration'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('key_prefix')
                    ->label('Key Prefix')
                    ->fontFamily('mono'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('allowed_slugs')
                    ->label('Bots')
                    ->state(fn (TelegramServiceKey $record): string => $record->allowed_slugs
                        ? implode(', ', $record->allowed_slugs)
                        : 'All')
                    ->limit(30),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->since()
                    ->placeholder('Never'),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('Never'),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TelegramServiceKey $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (TelegramServiceKey $record) {
                        $record->update(['is_active' => false]);
                        Notification::make()->success()->title('Key revoked')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\TelegramServiceKeyResource\Pages\ListTelegramServiceKeys::route('/'),
            'create' => \App\Filament\Resources\TelegramServiceKeyResource\Pages\CreateTelegramServiceKey::route('/create'),
        ];
    }
}
