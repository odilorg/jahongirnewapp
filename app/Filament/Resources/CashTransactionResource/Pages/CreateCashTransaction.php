<?php

namespace App\Filament\Resources\CashTransactionResource\Pages;

use App\Filament\Resources\CashTransactionResource;
use App\Enums\TransactionType;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateCashTransaction extends CreateRecord
{
    protected static string $resource = CashTransactionResource::class;

    public function mount(): void
    {
        $user = auth()->user();
        
        // Check if cashier has an open shift
        if ($user->hasRole('cashier')) {
            $userShift = \App\Models\CashierShift::getUserOpenShift($user->id);
            if (!$userShift) {
                \Filament\Notifications\Notification::make()
                    ->title('No Open Shift')
                    ->body('You must have an open shift to create transactions. Please start a shift first.')
                    ->warning()
                    ->persistent()
                    ->send();
                    
                $this->redirect(route('filament.admin.resources.cashier-shifts.start-shift'));
                return;
            }
        }
        
        parent::mount();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Automatically set created_by to current user if not set
        if (empty($data['created_by'])) {
            $data['created_by'] = auth()->id();
        }

        // For cashiers, automatically set their open shift if not already set
        $user = auth()->user();
        if ($user->hasRole('cashier') && empty($data['cashier_shift_id'])) {
            $userShift = \App\Models\CashierShift::getUserOpenShift($user->id);
            if ($userShift) {
                $data['cashier_shift_id'] = $userShift->id;
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Simple transaction created - no additional processing needed
    }
}
