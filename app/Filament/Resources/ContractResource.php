<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Contract;
use Filament\Forms\Form;
use App\Mail\SendContract;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Mail;
use Filament\Notifications\Notification;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Resources\ContractResource\Pages;
use App\Services\TurfirmaService; // Import the TurfirmaService


class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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

                Forms\Components\DatePicker::make('date')
                    ->native(false)
                    ->closeOnDateSelection()
                    ->required(),
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
                    ->sortable()
                    ->searchable(),
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
                        $turfirmaEmail = $record->turfirma?->email; // Safely access the email via the relationship

                        if ($turfirmaEmail) {
                            Mail::to($turfirmaEmail)->queue(new SendContract($record));

                            // Add a success notification
                            Notification::make()
                                ->title('Contract Sent Successfully')
                                ->body('The contract has been sent to ' . $record->turfirma->name . ' (' . $turfirmaEmail . ').')
                                ->success()
                                ->send();
                        } else {
                            // Add an error notification
                            Notification::make()
                                ->title('Failed to Send Contract')
                                ->body('No email address found for the partner.')
                                ->danger()
                                ->send();
                        }
                    }),
                    Tables\Actions\ViewAction::make(),
   


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
            'index' => Pages\ListContracts::route('/'),
            'create' => Pages\CreateContract::route('/create'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
            'view' => Pages\ViewContract::route('/{record}'),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('number')
                ->color('primary'),
                TextEntry::make('date')
                ->color('primary'),
                TextEntry::make('hotel.name')
                ->color('primary'),
                TextEntry::make('turfirma.name')
                ->color('primary'),
                    

            ]);
    }
}
