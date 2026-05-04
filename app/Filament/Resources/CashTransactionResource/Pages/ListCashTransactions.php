<?php

namespace App\Filament\Resources\CashTransactionResource\Pages;

use App\Actions\Cashier\RecordMixedCurrencySplitFromAdminAction;
use App\Filament\Resources\CashTransactionResource;
use App\Models\Beds24Booking;
use App\Models\CashierShift;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCashTransactions extends ListRecords
{
    protected static string $resource = CashTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            // Phase 1.5.1 — Admin Manual Mixed-Currency Journal Builder.
            // Visible to roles that can already edit shift saldos (super_admin
            // / admin / manager). Cashiers do not see this; they use the bot.
            //
            // Real-world driver 2026-05-04: booking 1,115,000 UZS settled
            // as 500,000 UZS card + $50 USD cash. Bot couldn't capture; now
            // managers can record it from this admin surface without waiting
            // for Phase 1.5.2 bot UX.
            //
            // The action delegates ALL business logic to
            // RecordMixedCurrencySplitFromAdminAction so the same path is
            // reusable from CLI, jobs, or future bot flow without
            // duplicating sum-lock / FX / journal logic.
            Actions\Action::make('recordMixedCurrencyJournal')
                ->label('💱 Mixed-currency journal')
                ->color('warning')
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']) ?? false)
                ->modalHeading('Record mixed-currency split payment')
                ->modalDescription('Two settlement legs in different currencies for one booking. Frozen FX rates from current presentation. Sum-lock validates in the booking base currency.')
                ->form([
                    Forms\Components\Select::make('cashier_shift_id')
                        ->label('Open shift')
                        ->options(fn () => CashierShift::query()
                            ->where('status', 'open')
                            ->with('user:id,name')
                            ->get()
                            ->mapWithKeys(fn ($s) => [$s->id => "#{$s->id} — {$s->user?->name} (opened {$s->opened_at?->format('d.m H:i')})"])
                            ->all())
                        ->required()
                        ->helperText('Drawer/shift this journal belongs to.'),

                    Forms\Components\TextInput::make('beds24_booking_id')
                        ->label('Beds24 booking ID')
                        ->required()
                        ->numeric()
                        ->helperText('Booking must exist in beds24_bookings + be payable. The frozen FX presentation is generated at submit.')
                        ->rule(function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if (! Beds24Booking::where('beds24_booking_id', $value)->exists()) {
                                    $fail("Booking #{$value} not found in local beds24_bookings.");
                                }
                            };
                        }),

                    Forms\Components\Select::make('base_currency')
                        ->label('Base currency (commercial-truth)')
                        ->options([
                            'UZS' => 'UZS',
                            'USD' => 'USD',
                            'EUR' => 'EUR',
                        ])
                        ->required()
                        ->default('UZS')
                        ->helperText('Sum-lock reconciles to this currency. Pick whatever the booking is presented at on Beds24.'),

                    Forms\Components\Section::make('Leg 1')
                        ->schema([
                            Forms\Components\Select::make('leg1_currency')
                                ->label('Currency')
                                ->options(['UZS' => 'UZS', 'USD' => 'USD', 'EUR' => 'EUR'])
                                ->required(),
                            Forms\Components\TextInput::make('leg1_amount')
                                ->label('Amount')
                                ->numeric()
                                ->required()
                                ->minValue(0.01),
                            Forms\Components\Select::make('leg1_method')
                                ->label('Method')
                                ->options(['cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод'])
                                ->required(),
                        ])
                        ->columns(3),

                    Forms\Components\Section::make('Leg 2')
                        ->schema([
                            Forms\Components\Select::make('leg2_currency')
                                ->label('Currency')
                                ->options(['UZS' => 'UZS', 'USD' => 'USD', 'EUR' => 'EUR'])
                                ->required(),
                            Forms\Components\TextInput::make('leg2_amount')
                                ->label('Amount')
                                ->numeric()
                                ->required()
                                ->minValue(0.01),
                            Forms\Components\Select::make('leg2_method')
                                ->label('Method')
                                ->options(['cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод'])
                                ->required(),
                        ])
                        ->columns(3),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(RecordMixedCurrencySplitFromAdminAction::class)->execute($data);

                        Notification::make()
                            ->title('Mixed-currency journal recorded')
                            ->body("Journal {$result['journal_uuid']} — leg 1 #{$result['tx1_id']}, leg 2 #{$result['tx2_id']}")
                            ->success()
                            ->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Sum-lock or validation failure')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Mixed-currency journal action failed', [
                            'data'  => $data,
                            'error' => $e->getMessage(),
                        ]);
                        Notification::make()
                            ->title('Recording failed')
                            ->body('See laravel.log for details. Error class: ' . class_basename($e))
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
