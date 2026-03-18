<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramBotResource\RelationManagers;

use App\Enums\AccessAction;
use App\Enums\AccessResult;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AccessLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'accessLogs';

    protected static ?string $title = 'Recent Audit Logs';

    protected static ?string $recordTitleAttribute = 'action';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->color(fn (AccessAction $state): string => match (true) {
                        $state->isPrivilegedSecretAccess() => 'danger',
                        $state === AccessAction::Error => 'danger',
                        $state === AccessAction::MessageSent => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('result')
                    ->badge()
                    ->color(fn (AccessResult $state): string => match ($state) {
                        AccessResult::Success => 'success',
                        AccessResult::Denied => 'danger',
                        AccessResult::NotFound => 'warning',
                        AccessResult::Error => 'danger',
                    }),

                Tables\Columns\TextColumn::make('actor_type')
                    ->label('Actor')
                    ->placeholder('System'),

                Tables\Columns\TextColumn::make('service_name')
                    ->label('Service')
                    ->limit(40)
                    ->tooltip(fn ($state): ?string => $state),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('result')
                    ->options(AccessResult::class),
                Tables\Filters\SelectFilter::make('action')
                    ->options(AccessAction::class),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }
}
