<?php

namespace App\Filament\Resources\TurfirmaResource\Pages;

use App\Filament\Resources\TurfirmaResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use App\Services\TurfirmaService;

class ListTurfirmas extends ListRecords
{
    protected static string $resource = TurfirmaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('create_from_tin')
                ->label('Create from TIN')
                ->modalHeading('Create Turfirma from TIN')
                ->form([
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
                ->action(function (array $data) {
                    // Delegate the logic to TurfirmaService
                    TurfirmaService::createOrFetchTurfirma($data);

                    
                }),
        ];
    }
}
