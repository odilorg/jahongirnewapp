<?php

namespace App\Filament\Resources\AccommodationResource\RelationManagers;

use App\Filament\RelationManagers\SupplierPaymentsRelationManager;

class PaymentsRelationManager extends SupplierPaymentsRelationManager
{
    protected string $supplierType = 'accommodation';
}
