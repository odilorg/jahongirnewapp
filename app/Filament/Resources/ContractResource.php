<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Contract;
use Filament\Forms\Form;
use App\Mail\SendContract;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use App\Filament\Resources\ContractResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ContractResource\RelationManagers;


class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Hotel Related';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('hotel_id')
                    ->relationship('hotel', 'name')
                    ->required(),
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

                        // Fetch data from the API using the provided TIN
                        $response = Http::get("https://gnk-api.didox.uz/api/v1/utils/info/{$data['tin']}");

                        if (!$response->successful() || empty($response->json('shortName')) || empty($response->json('name'))) {
                            // Show a notification if the API fetch fails
                            Notification::make()
                                ->title('Error Fetching Data')
                                ->body('The database is down. Please add the company details manually.')
                                ->danger()
                                ->send();

                            throw ValidationException::withMessages([
                                'tin' => 'Failed to fetch data. Please verify the TIN or add the data manually.',
                            ]);
                        }

                        $companyData = $response->json();

                        // Create a new Turfirma record in the database
                        $newTurfirma = \App\Models\Turfirma::create([
                            'name' => $companyData['shortName'] ?? null,
                            'official_name' => $companyData['name'] ?? null,
                            'address_street' => $companyData['address'] ?? null,
                            'inn' => $companyData['tin'] ?? $data['tin'],
                            'account_number' => $companyData['account'] ?? null,
                            'bank_mfo' => $companyData['bankCode'] ?? null,
                            'director_name' => $companyData['director'] ?? null,
                        ]);

                        // Return the primary key of the newly created Turfirma
                        return $newTurfirma->id;
                    }),

                Forms\Components\DatePicker::make('date')
                    //->format('d/m/Y')
                    ->native(false)
                    ->closeOnDateSelection()
                    ->required(),
                // Forms\Components\TextInput::make('number')
                //     ->required()
                //     ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('hotel.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('turfirma.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('number')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-s-cloud-arrow-down')
                    ->visible(fn(Contract $record): bool => !is_null($record->file_name))
                    ->action(function (Contract $record) {
                        return response()->download(storage_path('app/public/contracts/') . $record->file_name);
                    }),

                Tables\Actions\Action::make('send_contract')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn(Contract $record): bool => !is_null($record->file_name))
                    ->action(function (Contract $record) {
                        Mail::to($record->client_email)->queue(new SendContract($record));
                    }),
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
            'index' => Pages\ListContracts::route('/'),
            'create' => Pages\CreateContract::route('/create'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
        ];
    }
}
