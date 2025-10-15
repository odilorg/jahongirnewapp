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
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->maxLength(255),
                        Forms\Components\Select::make('roles')
                            ->label('Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder('Select one or more roles')
                            ->helperText('Users can have multiple roles (e.g., Manager + Cashier)')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Location Assignment')
                    ->description('Assign locations where this user can work (for cashiers/staff)')
                    ->schema([
                        Forms\Components\Select::make('locations')
                            ->label('Assigned Locations')
                            ->relationship('locations', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->helperText('Select one or more locations where this user can start shifts'),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => $record?->locations->isEmpty() ?? true),

                Forms\Components\Section::make('Phone Number')
                    ->description('Required for both Telegram bots')
                    ->schema([
                        Forms\Components\TextInput::make('phone_number')
                            ->label('Phone Number')
                            ->tel()
                            ->maxLength(50)
                            ->placeholder('998901234567')
                            ->helperText('Enter digits only, no spaces or + symbol. Required for Telegram bot authentication.')
                            ->rule('regex:/^[0-9]+$/'),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('🟢 POS Bot Access')
                    ->description('For cashiers to manage shifts and transactions via Telegram')
                    ->schema([
                        Forms\Components\Toggle::make('pos_bot_enabled')
                            ->label('Enable POS Bot Access')
                            ->helperText('User can authenticate and use POS bot features')
                            ->default(false)
                            ->live(),
                        Forms\Components\Placeholder::make('pos_requirements')
                            ->label('Requirements')
                            ->content(function ($record) {
                                if (!$record) return 'ℹ️ Save user first to see requirements';
                                
                                $checks = [];
                                $checks[] = $record->phone_number ? '✅ Phone number set' : '❌ Phone number required';
                                $checks[] = $record->hasAnyRole(['cashier', 'manager', 'super_admin']) 
                                    ? '✅ Has required role (cashier/manager/super_admin)' 
                                    : '⚠️ Needs role: cashier, manager, or super_admin';
                                $checks[] = $record->locations->count() > 0 
                                    ? '✅ Assigned to ' . $record->locations->count() . ' location(s)' 
                                    : '⚠️ Must be assigned to at least one location';
                                
                                return implode("\n", $checks);
                            }),
                        Forms\Components\Placeholder::make('pos_auth_status')
                            ->label('Authentication Status')
                            ->content(fn ($record) => 
                                $record?->telegram_pos_user_id 
                                    ? '✅ Authenticated - Telegram ID: ' . $record->telegram_pos_user_id 
                                    : '⏳ Not authenticated yet'
                            ),
                        Forms\Components\TextInput::make('telegram_pos_username')
                            ->label('Telegram Username')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Auto-populated after authentication'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn ($record) => !$record?->pos_bot_enabled),

                Forms\Components\Section::make('🏨 Booking Bot Access')
                    ->description('For staff to manage hotel reservations via Telegram')
                    ->schema([
                        Forms\Components\Toggle::make('booking_bot_enabled')
                            ->label('Enable Booking Bot Access')
                            ->helperText('User can authenticate and use booking bot features')
                            ->default(false)
                            ->live(),
                        Forms\Components\Placeholder::make('booking_requirements')
                            ->label('Requirements')
                            ->content(function ($record) {
                                if (!$record) return 'ℹ️ Save user first to see requirements';
                                
                                return $record->phone_number 
                                    ? '✅ Phone number set - Ready to use!' 
                                    : '❌ Phone number required';
                            }),
                        Forms\Components\Placeholder::make('booking_auth_status')
                            ->label('Authentication Status')
                            ->content(fn ($record) => 
                                $record?->telegram_booking_user_id 
                                    ? '✅ Authenticated - Telegram ID: ' . $record->telegram_booking_user_id 
                                    : '⏳ Not authenticated yet'
                            ),
                        Forms\Components\TextInput::make('telegram_booking_username')
                            ->label('Telegram Username')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Auto-populated after authentication'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn ($record) => !$record?->booking_bot_enabled),
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
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(',')
                    ->searchable()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin', 'admin' => 'success',
                        'manager' => 'warning',
                        'cashier' => 'info',
                        'staff' => 'gray',
                        default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('locations.name')
                    ->label('Assigned Locations')
                    ->badge()
                    ->separator(',')
                    ->searchable()
                    ->placeholder('No locations assigned')
                    ->color('primary')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label('Phone')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-phone')
                    ->placeholder('No phone'),
                Tables\Columns\IconColumn::make('pos_bot_enabled')
                    ->label('POS Bot')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('booking_bot_enabled')
                    ->label('Booking Bot')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->toggleable(),
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
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Filter by Role')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\SelectFilter::make('locations')
                    ->label('Filter by Location')
                    ->relationship('locations', 'name')
                    ->multiple()
                    ->preload(),
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
