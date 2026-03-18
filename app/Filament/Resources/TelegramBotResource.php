<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Enums\SecretStatus;
use App\Filament\Resources\TelegramBotResource\Pages;
use App\Filament\Resources\TelegramBotResource\RelationManagers;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Crypt;

/**
 * Ops console for Telegram bot fleet.
 *
 * Supports: list, view, create (import from BotFather), edit metadata,
 * soft delete (decommission). Token rotation and status lifecycle are
 * on the view page actions.
 *
 * Security: No secret values are displayed. Create form accepts token
 * via password field, encrypts immediately. Edit form cannot change
 * slug (code depends on it) or token (use Rotate Token action instead).
 */
class TelegramBotResource extends Resource
{
    protected static ?string $model = TelegramBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Telegram';

    protected static ?string $navigationLabel = 'Bots';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Restrict entire resource to super_admin only.
     * Matches the authorization pattern used by UserResource.
     */
    public static function canViewAny(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return $user !== null && $user->hasRole('super_admin');
    }

    public static function canCreate(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return $user !== null && $user->hasRole('super_admin');
    }

    // ──────────────────────────────────────────────
    // Form (create + edit)
    // ──────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Bot Identity')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->regex('/^[a-z0-9\-]+$/')
                            ->helperText('Lowercase, hyphens only. Used in code — cannot be changed after creation.')
                            ->disabled(fn (?TelegramBot $record): bool => $record !== null)
                            ->dehydrated()
                            ->maxLength(50),

                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(100),

                        Forms\Components\TextInput::make('bot_username')
                            ->label('@username')
                            ->placeholder('Filled automatically by Test Connection')
                            ->maxLength(100),

                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Environment')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('environment')
                            ->options(BotEnvironment::class)
                            ->default(BotEnvironment::fromAppEnvironment((string) app()->environment())->value)
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->options(BotStatus::class)
                            ->default(BotStatus::Active->value)
                            ->required()
                            ->disabled(fn (?TelegramBot $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText($form->getRecord() ? 'Use Disable/Enable/Revoke actions to change status.' : null),
                    ]),

                Forms\Components\Section::make('Bot Token')
                    ->description('Paste the token from BotFather. It will be encrypted immediately and never shown again.')
                    ->visible(fn (?TelegramBot $record): bool => $record === null) // Create only
                    ->schema([
                        Forms\Components\TextInput::make('initial_token')
                            ->label('Bot Token')
                            ->required()
                            ->password()
                            ->revealable()
                            ->placeholder('123456:ABC-DEF...')
                            ->minLength(20)
                            ->maxLength(200),

                        Forms\Components\TextInput::make('initial_webhook_secret')
                            ->label('Webhook Secret (optional)')
                            ->password()
                            ->revealable()
                            ->placeholder('Leave empty if not using webhook verification')
                            ->maxLength(256),
                    ]),
            ]);
    }

    // ──────────────────────────────────────────────
    // Table (list page)
    // ──────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (BotStatus $state): string => $state->color()),

                Tables\Columns\TextColumn::make('environment')
                    ->badge()
                    ->color(fn (BotEnvironment $state): string => $state->color()),

                Tables\Columns\IconColumn::make('has_active_secret')
                    ->label('Secret')
                    ->state(fn (TelegramBot $record): bool => $record->activeSecret()->exists())
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->since()
                    ->sortable()
                    ->placeholder('Never'),

                Tables\Columns\TextColumn::make('last_error_at')
                    ->label('Last Error')
                    ->since()
                    ->sortable()
                    ->placeholder('None')
                    ->color('danger'),
            ])
            ->defaultSort('slug')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(BotStatus::class),
                Tables\Filters\SelectFilter::make('environment')
                    ->options(BotEnvironment::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->label('Decommission')
                    ->modalHeading('Decommission Bot')
                    ->modalDescription('This soft-deletes the bot. It will be hidden from the list but its audit trail and secret history are preserved. The bot can be restored later if needed.'),
            ])
            ->bulkActions([]);
    }

    // ──────────────────────────────────────────────
    // Infolist (view page)
    // ──────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Bot Identity')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('slug')
                            ->copyable()
                            ->weight('bold'),
                        Infolists\Components\TextEntry::make('name'),
                        Infolists\Components\TextEntry::make('bot_username')
                            ->label('@username')
                            ->placeholder('Not set')
                            ->prefix('@'),
                        Infolists\Components\TextEntry::make('description')
                            ->placeholder('No description')
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make('Status & Environment')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (BotStatus $state): string => $state->color()),
                        Infolists\Components\TextEntry::make('environment')
                            ->badge()
                            ->color(fn (BotEnvironment $state): string => $state->color()),
                        Infolists\Components\TextEntry::make('source_label')
                            ->label('Source')
                            ->state(fn (TelegramBot $record): string => 'Database')
                            ->badge()
                            ->color('info'),
                    ]),

                Infolists\Components\Section::make('Secret Status')
                    ->description('Token values are never displayed. Only presence and version are shown.')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\IconEntry::make('has_active_secret')
                            ->label('Active Secret')
                            ->state(fn (TelegramBot $record): bool => $record->activeSecret()->exists())
                            ->boolean()
                            ->trueIcon('heroicon-o-lock-closed')
                            ->falseIcon('heroicon-o-lock-open')
                            ->trueColor('success')
                            ->falseColor('danger'),
                        Infolists\Components\TextEntry::make('active_secret_version')
                            ->label('Secret Version')
                            ->state(fn (TelegramBot $record): string => $record->activeSecret?->version
                                ? 'v' . $record->activeSecret->version
                                : 'None')
                            ->placeholder('No active secret'),
                        Infolists\Components\IconEntry::make('has_webhook_secret')
                            ->label('Webhook Secret')
                            ->state(fn (TelegramBot $record): bool => $record->activeSecret?->webhook_secret_encrypted !== null)
                            ->boolean()
                            ->trueIcon('heroicon-o-shield-check')
                            ->falseIcon('heroicon-o-shield-exclamation')
                            ->trueColor('success')
                            ->falseColor('warning'),
                        Infolists\Components\TextEntry::make('total_secret_versions')
                            ->label('Total Versions')
                            ->state(fn (TelegramBot $record): int => $record->secrets()->count()),
                    ]),

                Infolists\Components\Section::make('Operational Status')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('last_used_at')
                            ->label('Last Used')
                            ->since()
                            ->placeholder('Never'),
                        Infolists\Components\TextEntry::make('last_error_at')
                            ->label('Last Error')
                            ->since()
                            ->placeholder('No errors recorded')
                            ->color('danger'),
                        Infolists\Components\TextEntry::make('last_error_code')
                            ->placeholder('None')
                            ->visible(fn (TelegramBot $record): bool => $record->last_error_code !== null),
                        Infolists\Components\TextEntry::make('last_error_summary')
                            ->placeholder('None')
                            ->visible(fn (TelegramBot $record): bool => $record->last_error_summary !== null)
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make('Metadata')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('creator.name')
                            ->label('Created By')
                            ->placeholder('System'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }

    // ──────────────────────────────────────────────
    // Relations
    // ──────────────────────────────────────────────

    public static function getRelations(): array
    {
        return [
            RelationManagers\AccessLogsRelationManager::class,
        ];
    }

    // ──────────────────────────────────────────────
    // Pages
    // ──────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramBots::route('/'),
            'create' => Pages\CreateTelegramBot::route('/create'),
            'view' => Pages\ViewTelegramBot::route('/{record}'),
            'edit' => Pages\EditTelegramBot::route('/{record}/edit'),
        ];
    }
}
