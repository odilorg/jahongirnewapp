<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Zayavka;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Http;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use App\Filament\Resources\ZayavkaResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ZayavkaResource\RelationManagers;

class ZayavkaResource extends Resource
{
    protected static ?string $model = Zayavka::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Hotel Related';


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
                        Forms\Components\TextInput::make('tin')
                            ->label('TIN')
                            ->required()
                            ->numeric()
                            ->minLength(9)
                            ->maxLength(9)
                            ->hint('Enter the 9-digit TIN to fetch data'),
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
                        // Check if the company already exists in the database
                        $existingTurfirma = \App\Models\Turfirma::where('inn', $data['tin'])->first();

                        if ($existingTurfirma) {
                            // Show a notification if the company already exists
                            Notification::make()
                                ->title('Duplicate Entry')
                                ->body('A company with this TIN already exists.')
                                ->success()
                                ->send();

                            // Return the ID of the existing company
                            return $existingTurfirma->id;
                        }

                        // Initialize variable for the API data
                        $apiData = null;

                        // Try the primary API endpoint
                        $primaryResponse = Http::get("https://gnk-api.didox.uz/api/v1/utils/info/{$data['tin']}");
                        if ($primaryResponse->successful() && !empty($primaryResponse->json('shortName')) && !empty($primaryResponse->json('name'))) {
                            $apiData = $primaryResponse->json();
                        } else {
                            // If the primary API fails, try the first backup endpoint
                            $backupResponse = Http::get("https://new.soliqservis.uz/api/np1/bytin/factura?tinOrPinfl={$data['tin']}");
                            if ($backupResponse->successful() && !empty($backupResponse->json('shortName')) && !empty($backupResponse->json('name'))) {
                                $apiData = $backupResponse->json();
                            } else {
                                // If the first backup API fails, try the second backup endpoint
                                $secondBackupResponse = Http::get("https://stage.goodsign.biz/v1/utils/info/{$data['tin']}");
                                if ($secondBackupResponse->successful() && !empty($secondBackupResponse->json('shortName')) && !empty($secondBackupResponse->json('name'))) {
                                    $apiData = $secondBackupResponse->json();
                                }
                            }
                        }

                        // If no API returned valid data
                        if (!$apiData) {
                            Notification::make()
                                ->title('Error Fetching Data')
                                ->body('All APIs are down, or the TIN is invalid. Please add the company details manually.')
                                ->danger()
                                ->send();

                            throw ValidationException::withMessages([
                                'tin' => 'Failed to fetch data from all APIs. Please verify the TIN or add the data manually.',
                            ]);
                        }

                        // Create a new Turfirma record in the database
                        $newTurfirma = \App\Models\Turfirma::create([
                            'name' => $apiData['shortName'] ?? null,
                            'official_name' => $apiData['name'] ?? null,
                            'address_street' => $apiData['address'] ?? null,
                            'inn' => $apiData['tin'] ?? $data['tin'],
                            'account_number' => $apiData['account'] ?? null,
                            'bank_mfo' => $apiData['bankCode'] ?? $apiData['mfo'] ?? null, // Handles both bankCode (primary API) and mfo (backup APIs)
                            'director_name' => $apiData['director'] ?? null,
                            'phone' => $data['phone'], // Save the phone from the form
                            'email' => $data['email'], // Save the email from the form
                            'api_data' => json_encode($apiData), // Save the JSON data
                        ]);

                        // Return the primary key of the newly created Turfirma
                        return $newTurfirma->id;
                    }),

                Forms\Components\DatePicker::make('submitted_date')
                    //->format('d/m/Y')
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
                Forms\Components\Hidden::make('accepted_by')
                    ->default(fn () => auth()->id()) // Automatically set the authenticated user's ID
                    ->dehydrated(), // Ensure the value is saved to the database
                Forms\Components\Textarea::make('description')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('hotel_id')
                    ->relationship(name: 'hotel', titleAttribute: 'name')
                    ->required()
                    ->preload()
                    ->searchable(),

                Forms\Components\TextInput::make('notes')
                    //->required()
                    ->maxLength(255),
                Toggle::make('rooming'),

                FileUpload::make('image')
                    ->maxSize(6024)
                    ->acceptedFileTypes(['application/pdf', 'application/msword
'])
                    ->image()


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
                Tables\Columns\TextColumn::make('accepted_by')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('hotel.name')
                    ->numeric()
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
            'index' => Pages\ListZayavkas::route('/'),
            'create' => Pages\CreateZayavka::route('/create'),
            'edit' => Pages\EditZayavka::route('/{record}/edit'),
        ];
    }
}
