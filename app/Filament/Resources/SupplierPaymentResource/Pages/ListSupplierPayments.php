<?php

namespace App\Filament\Resources\SupplierPaymentResource\Pages;

use App\Filament\Resources\SupplierPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierPayments extends ListRecords
{
    protected static string $resource = SupplierPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
