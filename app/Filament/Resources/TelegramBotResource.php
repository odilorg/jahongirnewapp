<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Filament\Resources\TelegramBotResource\Pages;
use App\Filament\Resources\TelegramBotResource\RelationManagers;
use App\Models\TelegramBot;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Ops console for Telegram bot fleet.
 *
 * Read-only list + view. No create/edit flows (bots are provisioned
 * via seeder/importer, not the UI). Actions: test connection, webhook info.
 *
 * Security: No secret values are exposed. The view page shows only
 * whether an active secret exists and its version number.
 */
class TelegramBotResource extends Resource
{
    protected static ?string $model = TelegramBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Telegram';

    protected static ?string $navigationLabel = 'Bots';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
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
            'view' => Pages\ViewTelegramBot::route('/{record}'),
        ];
    }
}
