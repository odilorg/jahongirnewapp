<?php

namespace App\Filament\Resources\GuestPaymentResource\Pages;

use App\Filament\Resources\GuestPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateGuestPayment extends CreateRecord
{
    protected static string $resource = GuestPaymentResource::class;
}
