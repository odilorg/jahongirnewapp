<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingInquiryResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only view of the guest experience touchpoints for a booking
 * (Phase 29). No create/edit/delete — these rows are machine-managed by
 * the materializer + send command. Operators control them via the
 * "Disable guest experience messages" toggle on the booking form.
 */
class ExperienceMessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'experienceMessages';

    protected static ?string $title = 'Experience messages';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('message_type')
            ->columns([
                Tables\Columns\TextColumn::make('message_type')
                    ->label('Touchpoint')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'pending' => 'gray',
                        'skipped' => 'warning',
                        'sending' => 'info',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('channel'),
                Tables\Columns\TextColumn::make('due_at')->dateTime('M j, H:i')->label('Due'),
                Tables\Columns\TextColumn::make('sent_at')->dateTime('M j, H:i')->label('Sent')->placeholder('—'),
                Tables\Columns\TextColumn::make('last_error')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('due_at')
            ->paginated(false)
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
