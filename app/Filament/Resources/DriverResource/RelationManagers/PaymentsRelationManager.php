<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use App\Filament\RelationManagers\SupplierPaymentsRelationManager;

class PaymentsRelationManager extends SupplierPaymentsRelationManager
{
    protected string $supplierType = 'driver';
}
