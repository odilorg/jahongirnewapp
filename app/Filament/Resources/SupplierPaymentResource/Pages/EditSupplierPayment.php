<?php

namespace App\Filament\Resources\SupplierPaymentResource\Pages;

use App\Filament\Resources\SupplierPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplierPayment extends EditRecord
{
    protected static string $resource = SupplierPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
