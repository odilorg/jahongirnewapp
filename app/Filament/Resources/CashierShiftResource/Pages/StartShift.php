<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Actions\StartShiftAction;
use App\Filament\Resources\CashierShiftResource;
use App\Models\CashDrawer;
use App\Models\CashierShift;
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
            'beginning_saldo_uzs' => 0,
            'beginning_saldo_usd' => 0,
            'beginning_saldo_eur' => 0,
            'beginning_saldo_rub' => 0,
        ];
        
        $this->form->fill($formData);
    }

    public function form(Form $form): Form
    {
        $user = Auth::user();

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

                Forms\Components\Section::make(__c('multi_currency'))
                    ->description(__c('set_for_each_currency'))
                    ->schema([
                        Forms\Components\TextInput::make('beginning_saldo_uzs')
                            ->label(__c('uzs') . ' ' . __c('amount'))
                            ->numeric()
                            ->prefix('UZS')
                            ->minValue(0)
                            ->default(0),
                        Forms\Components\TextInput::make('beginning_saldo_usd')
                            ->label(__c('usd') . ' ' . __c('amount'))
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->default(0),
                        Forms\Components\TextInput::make('beginning_saldo_eur')
                            ->label(__c('eur') . ' ' . __c('amount'))
                            ->numeric()
                            ->prefix('€')
                            ->minValue(0)
                            ->default(0),
                        Forms\Components\TextInput::make('beginning_saldo_rub')
                            ->label(__c('rub') . ' ' . __c('amount'))
                            ->numeric()
                            ->prefix('₽')
                            ->minValue(0)
                            ->default(0),
                    ])
                    ->columns(2),

                Forms\Components\Textarea::make('notes')
                    ->label(__c('notes'))
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->statePath('data');
    }


    protected function handleRecordCreation(array $data): CashierShift
    {
        $user = Auth::user();
        $drawer = CashDrawer::query()->findOrFail($data['cash_drawer_id']);

        return app(StartShiftAction::class)->execute($user, $drawer, $data);
    }

    protected function afterCreate(): void
    {
        $shift = $this->record;

        Notification::make()
            ->title(__c('operation_successful'))
            ->body("New shift started on drawer '{$shift->cashDrawer->name}'")
            ->success()
            ->send();
            
        $this->redirect(route('filament.admin.resources.cashier-shifts.view', ['record' => $shift->id]));
    }
}