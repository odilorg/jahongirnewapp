<?php

namespace App\Filament\Resources\CashTransactionResource\Pages;

use App\Filament\Resources\CashTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCashTransaction extends CreateRecord
{
    protected static string $resource = CashTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Automatically set created_by to current user if not set
        if (empty($data['created_by'])) {
            $data['created_by'] = auth()->id();
        }

        return $data;
    }
}
