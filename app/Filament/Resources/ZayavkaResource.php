<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Zayavka;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use App\Services\TurfirmaService; // Import the service
use App\Filament\Resources\ZayavkaResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ZayavkaResource\RelationManagers;

class ZayavkaResource extends Resource
{
    protected static ?string $model = Zayavka::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Group Number')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('turfirma_id')
                    ->relationship('turfirma', 'name')
                    ->required()
                    ->preload()
                    ->searchable()
                    ->createOptionForm([
                        Forms\Components\Select::make('type')
                            ->label('Type')
                            ->options([
                                'tourfirm' => 'Tourfirm',
                                'individual' => 'Individual',
                            ])
                            ->required()
                            ->reactive()
                            ->default('tourfirm'),
                        Forms\Components\TextInput::make('tin')
                            ->label('TIN')
                            ->required()
                            ->numeric()
                            ->minLength(9)
                            ->maxLength(9)
                            ->hint('Enter the 9-digit TIN to fetch data')
                            ->hidden(fn($get) => $get('type') === 'individual')
                            ->dehydrated(fn($get) => $get('type') !== 'individual'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->hidden(fn($get) => $get('type') === 'tourfirm')
                            ->dehydrated(fn($get) => $get('type') !== 'tourfirm'),
                        Forms\Components\TextInput::make('phone')
                            ->label('Phone')
                            ->required()
                            ->tel()
                            ->hint('Enter the phone number'),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->required()
                            ->email()
                            ->hint('Enter a valid email address'),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        // Delegate the creation or fetching of Turfirma to the service
                        return TurfirmaService::createOrFetchTurfirma($data);
                    }),
                Forms\Components\DatePicker::make('submitted_date')
                    ->native(false)
                    ->closeOnDateSelection()
                    ->required(),
                Select::make('status')
                    ->options([
                        'accepted' => 'Accepted',
                        'no_room_avil' => 'No Rooms',
                        'waiting' => 'Waiting List',
                    ]),
                Forms\Components\TextInput::make('source')
                    ->label('Booking source, phone, email, name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Hidden::make('user_id')
                    ->default(fn() => auth()->id())
                    ->dehydrated(),
                Forms\Components\Textarea::make('description')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('hotel_id')
                    ->relationship(name: 'hotel', titleAttribute: 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                Forms\Components\TextInput::make('notes')
                    ->maxLength(255),
                Toggle::make('rooming'),
                FileUpload::make('image')
                    ->maxSize(6024)
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'image/jpeg'
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('turfirma.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('source')
                    ->searchable(),
                Tables\Columns\TextColumn::make('acceptedBy.name')
                    ->label('Accepted By')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('hotel.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('image')
                    ->label('Zayavka File')
                    ->url(fn($record) => asset('storage/' . $record->image))
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn($state) => 'Download'),
            ])
            ->filters([])
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZayavkas::route('/'),
            'create' => Pages\CreateZayavka::route('/create'),
            'edit' => Pages\EditZayavka::route('/{record}/edit'),
        ];
    }
}
