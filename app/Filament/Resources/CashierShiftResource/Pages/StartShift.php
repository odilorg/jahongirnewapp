<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Actions\StartShiftAction;
use App\Enums\Currency;
use App\Filament\Resources\CashierShiftResource;
use App\Models\CashDrawer;
use App\Models\ShiftTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class StartShift extends CreateRecord
{
    protected static string $resource = CashierShiftResource::class;

    protected static ?string $title = 'Start New Shift';

    public ?array $data = [];

    public function mount(): void
    {
        parent::mount();
        
        $user = Auth::user();
        $isManagerOrAdmin = $user->hasAnyRole(['super_admin', 'admin', 'manager']);
        
        // Check if user already has an open shift
        $existingShift = \App\Models\CashierShift::getUserOpenShift($user->id);
            
        if ($existingShift) {
            Notification::make()
                ->title('Cannot Start New Shift')
                ->body("You already have an open shift on drawer '{$existingShift->cashDrawer->name}'. Please close it before starting a new shift.")
                ->warning()
                ->persistent()
                ->send();
                
            $this->redirect(route('filament.admin.resources.cashier-shifts.view', ['record' => $existingShift->id]));
            return;
        }
        
        $formData = [
            'user_id' => $user->id, // Always set current user
            'beginning_saldo' => 0, // Default to 0 for everyone
        ];
        
        $this->form->fill($formData);
    }

    public function form(Form $form): Form
    {
        $user = Auth::user();
        $isManagerOrAdmin = $user->hasAnyRole(['super_admin', 'admin', 'manager']);
        
        return $form
            ->schema([
                Forms\Components\Hidden::make('user_id')
                    ->default(auth()->id()),
                Forms\Components\Select::make('cash_drawer_id')
                    ->label(__c('cash_drawer'))
                    ->options(CashDrawer::active()->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->preload(),
                
                // Legacy beginning saldo for UZS
                Forms\Components\TextInput::make('beginning_saldo')
                    ->label(__c('beginning_saldo') . ' (UZS)')
                    ->numeric()
                    ->prefix('UZS')
                    ->minValue(0)
                    ->default(0)
                    ->helperText(__c('beginning_saldo') . ' ' . __('cash.uzs')),
                
                Forms\Components\Textarea::make('notes')
                    ->label(__c('notes'))
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->statePath('data');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        
        // Ensure user_id and beginning_saldo are set
        $data['user_id'] = $user->id;
        $data['beginning_saldo'] = $data['beginning_saldo'] ?? $data['beginning_saldo_uzs'] ?? 0;
        $data['opened_at'] = now();
        
        return $data;
    }

    protected function afterCreate(): void
    {
        $shift = $this->record;
        
        // Create beginning saldo for UZS if amount is provided
        $uzsAmount = $shift->beginning_saldo ?? 0;
        if ($uzsAmount > 0) {
            \App\Models\BeginningSaldo::create([
                'cashier_shift_id' => $shift->id,
                'currency' => Currency::UZS,
                'amount' => $uzsAmount,
            ]);
        }
        
        Notification::make()
            ->title(__c('operation_successful'))
            ->body("New shift started on drawer '{$shift->cashDrawer->name}'")
            ->success()
            ->send();
            
        $this->redirect(route('filament.admin.resources.cashier-shifts.view', ['record' => $shift->id]));
    }
}