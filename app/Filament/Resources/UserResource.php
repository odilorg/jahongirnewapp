<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\User;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\UserResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\UserResource\RelationManagers;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'Users Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('User Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->options([
                                'super_admin' => 'Super Admin',
                                'admin' => 'Admin',
                                'manager' => 'Manager',
                                'cashier' => 'Cashier',
                                'staff' => 'Staff',
                            ])
                            ->searchable()
                            ->placeholder('Select a role'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Telegram Bot Access')
                    ->schema([
                        Forms\Components\TextInput::make('phone_number')
                            ->label('Phone Number')
                            ->tel()
                            ->maxLength(50)
                            ->placeholder('+998XXXXXXXXX')
                            ->helperText('Users with phone numbers can authorize the Telegram bot'),
                        Forms\Components\TextInput::make('telegram_user_id')
                            ->label('Telegram User ID')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Auto-populated when user authorizes via bot'),
                        Forms\Components\TextInput::make('telegram_username')
                            ->label('Telegram Username')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Auto-populated when user authorizes via bot'),
                        Forms\Components\DateTimePicker::make('last_active_at')
                            ->label('Last Active (Bot)')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->searchable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin', 'admin' => 'success',
                        'manager' => 'warning',
                        'cashier' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label('Phone')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-phone')
                    ->placeholder('No phone'),
                Tables\Columns\TextColumn::make('telegram_username')
                    ->label('Telegram')
                    ->searchable()
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->placeholder('Not linked'),
                Tables\Columns\TextColumn::make('last_active_at')
                    ->label('Last Bot Activity')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->placeholder('Never')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('phone_number')
                    ->label('Bot Access')
                    ->placeholder('All users')
                    ->trueLabel('Has phone (can use bot)')
                    ->falseLabel('No phone')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('phone_number'),
                        false: fn (Builder $query) => $query->whereNull('phone_number'),
                    ),
                Tables\Filters\TernaryFilter::make('telegram_user_id')
                    ->label('Bot Status')
                    ->placeholder('All users')
                    ->trueLabel('Linked to Telegram')
                    ->falseLabel('Not linked')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('telegram_user_id'),
                        false: fn (Builder $query) => $query->whereNull('telegram_user_id'),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
    public static function canViewAny(): bool
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        
        return $user && $user->hasRole('super_admin');
    }
}
