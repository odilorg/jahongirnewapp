<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScheduledMessageResource\Pages;
use App\Models\ScheduledMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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

                Forms\Components\Select::make('frequency')
                    ->options([
                        'none' => 'None (One-Time)',
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly',
                        'yearly' => 'Yearly',
                    ])
                    ->default('none')
                    ->label('Frequency'),

                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                    ])
                    ->default('pending')
                    ->label('Status'),

                // Here's the magic: BelongsToMany with multiple()
                Forms\Components\Select::make('chats')
                    ->multiple()
                    ->relationship(
                        name: 'chats',          // The relationship name in ScheduledMessage model
                        titleAttribute: 'name'  // The column in Chat model used as the display label
                    )
                    ->searchable()
                    ->preload()
                    ->label('Associated Chats'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('message')->limit(50)
                ->searchable(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('scheduled_at')->dateTime()
                ->sortable(),
                // Show how many chats are linked
                Tables\Columns\TextColumn::make('chats_count')
                    ->counts('chats')
                    ->label('Number of Chats'),
            ])
            ->filters([
                //
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
            'index' => Pages\ListScheduledMessages::route('/'),
            'create' => Pages\CreateScheduledMessage::route('/create'),
            'edit' => Pages\EditScheduledMessage::route('/{record}/edit'),
        ];
    }
}
