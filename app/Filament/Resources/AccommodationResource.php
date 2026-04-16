<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AccommodationResource\Pages;
use App\Models\Accommodation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Accommodation supplier admin — yurt camps, homestays, hotels.
 *
 * Lives in the consolidated "Tours" navigation group alongside
 * Website Inquiries, Drivers, Guides, Cars and Ratings — one place
 * for everything tour-operations related.
 * Phase 6 will add a Rate Cards relation manager here for tiered
 * per-occupancy pricing.
 */
class AccommodationResource extends Resource
{
    protected static ?string $model = Accommodation::class;

    protected static ?string $navigationIcon  = 'heroicon-o-home-modern';
    protected static ?string $navigationLabel = 'Accommodations';
    protected static ?string $navigationGroup = 'Tours';
    protected static ?int    $navigationSort  = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(191)
                        ->placeholder('Aydarkul Yurt Camp'),

                    Forms\Components\Select::make('type')
                        ->options([
                            'yurt'       => 'Yurt camp',
                            'homestay'   => 'Homestay',
                            'hotel'      => 'Hotel',
                            'guesthouse' => 'Guesthouse',
                        ])
                        ->native(false),

                    Forms\Components\TextInput::make('location')
                        ->maxLength(191)
                        ->placeholder('Aydarkul Lake'),

                    Forms\Components\TextInput::make('contact_name')
                        ->label('Manager / contact')
                        ->maxLength(191)
                        ->placeholder('Aka Sputnik'),

                    Forms\Components\TextInput::make('phone_primary')
                        ->label('Primary phone')
                        ->tel()
                        ->maxLength(64)
                        ->placeholder('+998 90 ...'),

                    Forms\Components\TextInput::make('phone_secondary')
                        ->label('Secondary phone')
                        ->tel()
                        ->maxLength(64),

                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->maxLength(191),

                    Forms\Components\TextInput::make('telegram_chat_id')
                        ->label('Telegram chat ID')
                        ->maxLength(64)
                        ->helperText('Optional. Leave blank — phone-based dispatch works without it.'),

                    Forms\Components\Toggle::make('is_active')
                        ->default(true),

                    Forms\Components\Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull()
                        ->placeholder('Languages, WiFi, special features, etc.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('location')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('contact_name')->label('Contact')->toggleable(),
                Tables\Columns\TextColumn::make('phone_primary')->label('Phone')->copyable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options([
                    'yurt'       => 'Yurt camp',
                    'homestay'   => 'Homestay',
                    'hotel'      => 'Hotel',
                    'guesthouse' => 'Guesthouse',
                ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active only')->default(true),
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
            AccommodationResource\RelationManagers\RatesRelationManager::class,
            AccommodationResource\RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccommodations::route('/'),
            'create' => Pages\CreateAccommodation::route('/create'),
            'edit'  => Pages\EditAccommodation::route('/{record}/edit'),
        ];
    }
}
