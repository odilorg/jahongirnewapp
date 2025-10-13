<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuthorizedStaffResource\Pages;
use App\Filament\Resources\AuthorizedStaffResource\RelationManagers;
use App\Models\AuthorizedStaff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AuthorizedStaffResource extends Resource
{
    protected static ?string $model = AuthorizedStaff::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Authorized Staff';

    protected static ?string $modelLabel = 'Authorized Staff';

    protected static ?string $pluralModelLabel = 'Authorized Staff';

    protected static ?string $navigationGroup = 'Telegram Bot';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Staff Information')
                    ->schema([
                        Forms\Components\TextInput::make('full_name')
                            ->label('Full Name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone_number')
                            ->label('Phone Number')
                            ->tel()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->placeholder('+998XXXXXXXXX')
                            ->helperText('International format with country code'),
                        Forms\Components\Select::make('role')
                            ->label('Role')
                            ->required()
                            ->options([
                                'admin' => 'Admin',
                                'manager' => 'Manager',
                                'staff' => 'Staff',
                            ])
                            ->default('staff'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->required()
                            ->default(true)
                            ->helperText('Inactive staff cannot use the bot'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Telegram Information')
                    ->schema([
                        Forms\Components\TextInput::make('telegram_user_id')
                            ->label('Telegram User ID')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Auto-populated when staff authorizes via bot'),
                        Forms\Components\TextInput::make('telegram_username')
                            ->label('Telegram Username')
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Auto-populated when staff authorizes via bot'),
                        Forms\Components\DateTimePicker::make('last_active_at')
                            ->label('Last Active')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label('Phone')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-phone'),
                Tables\Columns\TextColumn::make('telegram_username')
                    ->label('Telegram')
                    ->searchable()
                    ->placeholder('Not connected')
                    ->icon('heroicon-o-chat-bubble-left-right'),
                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'success',
                        'manager' => 'warning',
                        'staff' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('last_active_at')
                    ->label('Last Active')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->placeholder('Never'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'manager' => 'Manager',
                        'staff' => 'Staff',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListAuthorizedStaff::route('/'),
            'create' => Pages\CreateAuthorizedStaff::route('/create'),
            'edit' => Pages\EditAuthorizedStaff::route('/{record}/edit'),
        ];
    }
}
