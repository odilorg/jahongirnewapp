<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Turfirma;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\FileUpload;
use App\Filament\Resources\TurfirmaResource\Pages;

class TurfirmaResource extends Resource
{
    protected static ?string $model = Turfirma::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Hotel Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->options([
                        'tourfirm' => 'Tourfirm',
                        'individual' => 'Individual',
                    ])
                    ->required()
                    ->reactive() // Makes the form respond dynamically
                    ->default('tourfirm'),

                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('official_name')
                    ->required()
                    ->maxLength(255)
                    ->hidden(fn($get) => $get('type') === 'individual') // Hidden when Individual is selected
                    ->dehydrated(fn($get) => $get('type') !== 'individual'), // Not submitted when hidden
                Forms\Components\TextInput::make('address_street')
                    ->required()
                    ->hidden(fn($get) => $get('type') === 'individual') // Hidden when Individual is selected
                    ->dehydrated(fn($get) => $get('type') !== 'individual'), // Not submitted when hidden

                Forms\Components\TextInput::make('address_city')
                    ->required()->hidden(fn($get) => $get('type') === 'individual') // Hidden when Individual is selected
                    ->dehydrated(fn($get) => $get('type') !== 'individual'), // Not submitted when hidden
                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required(),

                Forms\Components\TextInput::make('inn')
                    ->required()
                    ->numeric()
                    ->rule('regex:/^\d{9}$/')
                    ->maxLength(9)
                    ->label('Taxpayer Identification Number (TIN)')
                    ->placeholder('Enter 9-digit TIN')
                    ->hidden(fn($get) => $get('type') === 'individual') // Hidden when Individual is selected
                    ->dehydrated(fn($get) => $get('type') !== 'individual'), // Not submitted when hidden

                Forms\Components\TextInput::make('account_number')
                    ->required()
                    ->numeric()
                    ->rule('regex:/^\d{20}$/')
                    ->maxLength(20)
                    ->label('Account Number')
                    ->placeholder('Enter 20-digit account number')
                    ->hidden(fn($get) => $get('type') === 'individual') // Hidden when Individual is selected
                    ->dehydrated(fn($get) => $get('type') !== 'individual'), // Not submitted when hidden

                Forms\Components\TextInput::make('bank_name')
                    ->maxLength(255)
                    ->hidden(fn($get) => $get('type') === 'individual'), // Hidden when Individual is selected,
                Forms\Components\TextInput::make('bank_mfo')
                    ->required()
                    ->numeric()
                    ->rule('regex:/^\d{5}$/')
                    ->maxLength(5)
                    ->label('Bank MFO Code')
                    ->placeholder('Enter 5-digit bank code')
                    ->hidden(fn($get) => $get('type') === 'individual') // Hidden when Individual is selected
                    ->dehydrated(fn($get) => $get('type') !== 'individual'), // Not submitted when hidden

                Forms\Components\TextInput::make('director_name')
                    ->required()
                    ->maxLength(255)
                    ->hidden(fn($get) => $get('type') === 'individual') // Hidden when Individual is selected
                    ->dehydrated(fn($get) => $get('type') !== 'individual'), // Not submitted when hidden
                FileUpload::make('files')
               // ->multiple()   
                ->maxSize(6024)
                   
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'image/jpeg'

                        ])        
                // Forms\Components\TextInput::make('social_media')
                //     ->label('Social Media')
                //     ->placeholder('Enter social media handle or link')
                //     ->hidden(fn ($get) => $get('type') === 'tourfirm'), // Shown only when Individual is selected
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
                Tables\Columns\TextColumn::make('official_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address_street')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address_city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('inn')
                    ->sortable(),
                Tables\Columns\TextColumn::make('account_number')
                    ->sortable(),
                Tables\Columns\TextColumn::make('bank_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('bank_mfo')
                    ->sortable(),
            ])
            ->filters([
                //
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTurfirmas::route('/'),
            'create' => Pages\CreateTurfirma::route('/create'),
            'edit' => Pages\EditTurfirma::route('/{record}/edit'),
        ];
    }
}
