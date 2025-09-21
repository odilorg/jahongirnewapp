<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Filament\Resources\CashierShiftResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateCashierShift extends CreateRecord
{
    protected static string $resource = CashierShiftResource::class;

    public function mount(): void
    {
        $user = auth()->user();
        
        // Check if user already has an open shift
        $existingShift = \App\Models\CashierShift::getUserOpenShift($user->id);
            
        if ($existingShift) {
            Notification::make()
                ->title('Cannot Create New Shift')
                ->body("You already have an open shift on drawer '{$existingShift->cashDrawer->name}'. Please close it before creating a new shift.")
                ->warning()
                ->persistent()
                ->send();
                
            $this->redirect(route('filament.admin.resources.cashier-shifts.view', ['record' => $existingShift->id]));
            return;
        }
        
        parent::mount();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Automatically set user_id to current user if not set
        if (empty($data['user_id'])) {
            $data['user_id'] = auth()->id();
        }

        // Set default beginning_saldo if not set
        if (empty($data['beginning_saldo'])) {
            $data['beginning_saldo'] = 0;
        }

        // Set opened_at if not set
        if (empty($data['opened_at'])) {
            $data['opened_at'] = now();
        }

        return $data;
    }
}
