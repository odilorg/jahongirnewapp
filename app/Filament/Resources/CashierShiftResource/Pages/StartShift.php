<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Actions\StartShiftAction;
use App\Filament\Resources\CashierShiftResource;
use App\Models\CashierShift;
use Filament\Resources\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Filament\Actions\Action;

class StartShift extends Page
{
    protected static string $resource = CashierShiftResource::class;

    protected static string $view = 'filament.resources.cashier-shift-resource.pages.start-shift';

    protected static ?string $title = 'Start New Shift';

    public $existingShift = null;
    public $autoSelectedInfo = null;

    public function mount(): void
    {
        $user = Auth::user();

        // Check if user already has an open shift
        $this->existingShift = CashierShift::getUserOpenShift($user->id);

        if ($this->existingShift) {
            return; // Show warning in the view
        }

        // Get auto-selected drawer info for preview
        $locations = $user->locations;

        if ($locations->isEmpty()) {
            $this->autoSelectedInfo = [
                'error' => 'You are not assigned to any locations. Please contact your manager.'
            ];
            return;
        }

        // Preview what will be auto-selected
        if ($locations->count() === 1) {
            $location = $locations->first();
            $drawer = \App\Models\CashDrawer::where('location_id', $location->id)
                ->where('is_active', true)
                ->whereDoesntHave('openShifts')
                ->first();

            if ($drawer) {
                // Get previous shift to show what balances will be carried over
                $previousShift = CashierShift::where('cash_drawer_id', $drawer->id)
                    ->where('status', \App\Enums\ShiftStatus::CLOSED)
                    ->orderBy('closed_at', 'desc')
                    ->with('endSaldos')
                    ->first();

                $balances = [];
                if ($previousShift && $previousShift->endSaldos->isNotEmpty()) {
                    foreach ($previousShift->endSaldos as $endSaldo) {
                        $balances[$endSaldo->currency->value] = $endSaldo->currency->formatAmount($endSaldo->counted_end_saldo);
                    }
                }

                $this->autoSelectedInfo = [
                    'location' => $location->name,
                    'drawer' => $drawer->name,
                    'balances' => $balances,
                    'has_previous_shift' => $previousShift !== null,
                ];
            } else {
                $this->autoSelectedInfo = [
                    'error' => "No available drawers at {$location->name}. All drawers have open shifts."
                ];
            }
        } else {
            $this->autoSelectedInfo = [
                'location' => 'Multiple locations',
                'drawer' => 'Auto-selected from: ' . $locations->pluck('name')->join(', '),
                'balances' => [],
                'has_previous_shift' => false,
            ];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('quickStart')
                ->label('Start Shift')
                ->icon('heroicon-o-play')
                ->color('success')
                ->size('lg')
                ->visible(fn() => !$this->existingShift && !isset($this->autoSelectedInfo['error']))
                ->requiresConfirmation()
                ->modalHeading('Start Your Shift?')
                ->modalDescription(function () {
                    if (!$this->autoSelectedInfo) {
                        return 'Starting shift...';
                    }

                    $location = $this->autoSelectedInfo['location'] ?? 'Unknown';
                    $drawer = $this->autoSelectedInfo['drawer'] ?? 'Unknown';
                    $balances = $this->autoSelectedInfo['balances'] ?? [];

                    $balanceText = empty($balances)
                        ? 'Starting with zero balances (no previous shift)'
                        : 'Carrying over balances: ' . implode(', ', $balances);

                    return "Location: {$location}\nDrawer: {$drawer}\n\n{$balanceText}";
                })
                ->modalSubmitActionLabel('Yes, Start Shift')
                ->action(function () {
                    try {
                        $user = Auth::user();
                        $shift = app(StartShiftAction::class)->quickStart($user);

                        Notification::make()
                            ->title('Shift Started Successfully')
                            ->body("Your shift has been started on drawer '{$shift->cashDrawer->name}' at {$shift->cashDrawer->location->name}.")
                            ->success()
                            ->send();

                        $this->redirect(route('filament.admin.resources.cashier-shifts.view', ['record' => $shift->id]));
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()
                            ->title('Cannot Start Shift')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Error Starting Shift')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
