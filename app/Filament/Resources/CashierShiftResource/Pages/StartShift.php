<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Actions\StartShiftAction;
use App\Enums\Currency;
use App\Filament\Resources\CashierShiftResource;
use App\Models\CashDrawer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class StartShift extends Page
{
    protected static string $resource = CashierShiftResource::class;

    protected static string $view = 'filament.resources.cashier-shift-resource.pages.start-shift';

    protected static ?string $title = 'Start New Shift';

    public ?array $data = [];

    public function mount(): void
    {
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
                    ->label('Cash Drawer')
                    ->options(CashDrawer::active()->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->preload(),
                       Forms\Components\TextInput::make('beginning_saldo')
                           ->label('Beginning Cash Amount')
                           ->numeric()
                           ->prefix('UZS')
                           ->required()
                           ->minValue(0)
                           ->default(0)
                           ->visible($isManagerOrAdmin) // Only managers/admins can set beginning saldo
                           ->helperText($isManagerOrAdmin ? 'Set the opening cash amount' : 'Beginning saldo will be calculated automatically'),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes (Optional)')
                    ->maxLength(1000),
            ])
            ->statePath('data');
    }

    public function startShift(): void
    {
        $data = $this->form->getState();
        $user = Auth::user();
        
        // Ensure required fields are set
        $data['user_id'] = $data['user_id'] ?? $user->id;
        $data['beginning_saldo'] = $data['beginning_saldo'] ?? 0;
        
        try {
            $drawer = CashDrawer::findOrFail($data['cash_drawer_id']);
            $shift = app(StartShiftAction::class)->execute($user, $drawer, $data);
            
            Notification::make()
                ->title('Shift Started Successfully')
                ->body("Shift #{$shift->id} has been started on {$drawer->name}")
                ->success()
                ->send();
                
            $this->redirect(route('filament.admin.resources.cashier-shifts.view', ['record' => $shift->id]));
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error Starting Shift')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}