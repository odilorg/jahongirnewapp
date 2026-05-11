<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashExpenseResource\Pages;

use App\Filament\Resources\CashExpenseResource;
use App\Models\Hotel;
use App\Services\Expenses\ConsolidatePettyCashService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCashExpenses extends ListRecords
{
    protected static string $resource = CashExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('consolidatePreviousMonth')
                ->label('Consolidate previous month')
                ->icon('heroicon-o-arrows-right-left')
                ->color('warning')
                ->form([
                    Forms\Components\Select::make('period')
                        ->label('Month')
                        ->options(fn () => $this->monthOptions())
                        ->default(Carbon::now()->subMonthNoOverflow()->format('Y-m'))
                        ->required(),
                    Forms\Components\Select::make('hotel_id')
                        ->label('Post to hotel')
                        ->options(Hotel::query()->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->helperText('All eligible petty-cash rows for the selected month will be posted to this hotel.'),
                ])
                ->modalDescription(
                    'Eligible rows for the selected month will be posted into main expenses. '
                    .'Already-consolidated and rejected rows are skipped. '
                    .'Any failure (e.g. missing FX rate) rolls back the entire batch.'
                )
                ->requiresConfirmation()
                ->modalSubmitActionLabel('Consolidate')
                ->action(fn (array $data) => $this->runConsolidation($data)),
        ];
    }

    /**
     * Run the consolidation service and translate outcome to a Filament
     * notification. Extracted from the action closure so the closure stays
     * within the arch-lint rule 11 length cap.
     */
    private function runConsolidation(array $data): void
    {
        /** @var ConsolidatePettyCashService $service */
        $service = app(ConsolidatePettyCashService::class);

        try {
            $posted = $service->consolidateMonth(
                $data['period'],
                (int) $data['hotel_id'],
                (int) auth()->id(),
            );

            Notification::make()
                ->title("Consolidated {$posted} petty-cash row(s) into main expenses.")
                ->success()
                ->send();
        } catch (\DomainException $e) {
            Notification::make()
                ->title('Consolidation aborted')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Build a 12-month dropdown for period selection — current month plus
     * 11 prior. Operator typically picks "previous month" but earlier
     * back-fills are allowed (late-arriving petty-cash entries).
     */
    private function monthOptions(): array
    {
        $opts = [];
        for ($i = 0; $i < 12; $i++) {
            $m = Carbon::now()->subMonthsNoOverflow($i);
            $opts[$m->format('Y-m')] = $m->format('F Y');
        }

        return $opts;
    }
}
