<?php

namespace App\Filament\Resources\CashTransactionResource\Pages;

use App\Actions\Cashier\RecordBulkGroupPaymentFromAdminAction;
use App\Actions\Cashier\RecordMixedCurrencySplitFromAdminAction;
use App\Actions\Cashier\RecordSmallSaleAction;
use App\Filament\Resources\CashTransactionResource;
use App\Models\Beds24Booking;
use App\Models\CashierShift;
use App\Models\IncomeCategory;
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

            // Phase 1.6.0 — Admin "Record Small Sale".
            // Captures petty / shop sales (water, snacks, souvenirs, tour
            // add-ons, tips, etc.) categorised by income_category_id so
            // reports can break down ancillary revenue. Visible to roles
            // that already operate the cash drawer (super_admin / admin /
            // manager); cashiers continue using the bot's 'sale' flow
            // until the bot's category picker ships in a future phase.
            //
            // Business logic lives in RecordSmallSaleAction so the same
            // path is reachable from CLI, jobs, and future bot UX.
            Actions\Action::make('recordSmallSale')
                ->label('🛍 Record Small Sale')
                ->color('success')
                ->icon('heroicon-o-shopping-bag')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']) ?? false)
                ->modalHeading('Record a small sale')
                ->modalDescription('Water, snacks, souvenirs, tour add-ons, tips. Attaches to the open cashier shift if one exists; otherwise admin-attributed.')
                ->form([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->placeholder('0.00'),
                        Forms\Components\Select::make('currency')
                            ->required()
                            ->default('UZS')
                            ->options([
                                'UZS' => 'UZS — Uzbek Som',
                                'USD' => 'USD — US Dollar',
                                'EUR' => 'EUR — Euro',
                            ]),
                    ]),
                    Forms\Components\Select::make('income_category_id')
                        ->label('Category')
                        ->required()
                        ->default(fn (): ?int => IncomeCategory::where('slug', 'water')->value('id'))
                        ->options(fn () => IncomeCategory::query()
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->pluck('name', 'id')
                            ->all()),
                    Forms\Components\Select::make('payment_method')
                        ->label('Payment method')
                        ->required()
                        ->default('cash')
                        ->options([
                            'cash'  => 'Cash',
                            'card'  => 'Card',
                            'karta' => 'Karta (UzCard / Humo)',
                        ]),
                    Forms\Components\Textarea::make('notes')
                        ->label('Note (optional)')
                        ->rows(2)
                        ->placeholder('e.g. 2 bottles, big group'),
                ])
                ->action(function (array $data): void {
                    $tx = app(RecordSmallSaleAction::class)
                        ->execute($data, auth()->id(), \App\Enums\CashTransactionSource::ManualAdmin);

                    Notification::make()
                        ->success()
                        ->title('Sale recorded')
                        ->body("#{$tx->id} · " . number_format((float) $tx->amount, 2) . ' ' . $tx->currency)
                        ->send();
                }),

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

                    // Phase 1.5.5 — FX variance fields. Hidden by default;
                    // operator only fills them on the SECOND submit when the
                    // first submit returned RequiresVarianceReasonException.
                    // The variance section's helper text shows the system's
                    // detected variance + implied vs frozen rate so the
                    // operator picks a reason with full context, not blind.
                    Forms\Components\Section::make('💱 FX variance')
                        ->description('Fill ONLY if first submit returned a variance prompt. Leave blank otherwise.')
                        ->collapsed()
                        ->schema([
                            Forms\Components\Select::make('fx_variance_reason')
                                ->label('Reason for variance')
                                ->options([
                                    'agreed_shop_rate'   => 'Agreed shop rate (operator ↔ guest)',
                                    'bill_denomination'  => 'Bill denomination rounding',
                                    'guest_overpay'      => 'Guest overpaid',
                                    'guest_underpay'     => 'Guest short — hotel absorbed',
                                    'rate_drift'         => 'Rate drift since presentation',
                                    'other'              => 'Other (specify in note)',
                                ])
                                ->helperText('First submit detects variance and surfaces the rate gap; pick a reason here on the second attempt.')
                                ->native(false),
                            Forms\Components\Textarea::make('fx_variance_note')
                                ->label('Variance note')
                                ->rows(2)
                                ->helperText('Required when reason = "Other". Optional otherwise — helps audit context.'),
                            Forms\Components\TextInput::make('fx_variance_manager_approval_id')
                                ->label('Manager approval ID')
                                ->numeric()
                                ->helperText('Required only when variance > 3% of booking total. Use FxManagerApproval row id.'),
                        ]),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(RecordMixedCurrencySplitFromAdminAction::class)->execute($data);

                        Notification::make()
                            ->title('Mixed-currency journal recorded')
                            ->body("Journal {$result['journal_uuid']} — leg 1 #{$result['tx1_id']}, leg 2 #{$result['tx2_id']}")
                            ->success()
                            ->send();
                    } catch (\App\Exceptions\RequiresVarianceReasonException $e) {
                        // Phase 1.5.5 — variance detected, operator must
                        // re-submit with a reason. Show a persistent
                        // notification with the full math so they pick
                        // the right reason without guesswork.
                        $p = $e->payload();
                        $variancePctLabel = number_format($p['variance_pct'], 2);
                        $varianceDir      = $p['variance_in_base'] > 0 ? 'overage (hotel gain)' : 'shortage (hotel absorbed)';
                        $impliedRate      = $p['implied_rate'] > 0 ? number_format($p['implied_rate'], 2) : '—';
                        $frozenRate       = $p['frozen_rate']  > 0 ? number_format($p['frozen_rate'],  2) : '—';

                        $msg = sprintf(
                            "📊 Variance detected\n\n"
                            . "Booking expects:    %s %s\n"
                            . "Legs total in base: %s %s\n"
                            . "Variance:           %s %s (%s%% — %s)\n\n"
                            . "Implied rate:       %s %s/foreign\n"
                            . "System frozen rate: %s %s/foreign\n\n"
                            . "%s\n\n"
                            . "Action: scroll down to '💱 FX variance' section, pick a reason, resubmit.",
                            number_format($p['expected_in_base'], 0, '.', ' '),
                            $p['base_currency'],
                            number_format($p['actual_in_base'],   0, '.', ' '),
                            $p['base_currency'],
                            ($p['variance_in_base'] > 0 ? '+' : '') . number_format($p['variance_in_base'], 0, '.', ' '),
                            $p['base_currency'],
                            $variancePctLabel,
                            $varianceDir,
                            $impliedRate, $p['base_currency'],
                            $frozenRate,  $p['base_currency'],
                            $p['requires_manager_approval']
                                ? '⚠ This variance band requires manager approval — fill the "Manager approval ID" field too.'
                                : 'ℹ Reason alone is sufficient (variance ≤ 3%).',
                        );

                        Notification::make()
                            ->title('FX variance — reason required')
                            ->body($msg)
                            ->warning()
                            ->persistent()
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

            // Phase 1.7.3 — Admin Bulk Group Payment.
            // Visible to super_admin / admin / manager (matches mixed-currency
            // journal action). Cashiers go through the bot for groups.
            Actions\Action::make('recordBulkGroupPayment')
                ->label('🏨 Bulk group payment')
                ->color('info')
                ->icon('heroicon-o-users')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']) ?? false)
                ->modalHeading('Record bulk payment for an entire group of bookings')
                ->modalDescription('All siblings settle at once with a single method. Same currency. Distributes proportionally via largest-remainder rounding.')
                ->form([
                    Forms\Components\Select::make('cashier_shift_id')
                        ->label('Open shift')
                        ->options(fn () => CashierShift::query()
                            ->where('status', 'open')
                            ->with('user:id,name')
                            ->get()
                            ->mapWithKeys(fn ($s) => [$s->id => "#{$s->id} — {$s->user?->name} (opened {$s->opened_at?->format('d.m H:i')})"])
                            ->all())
                        ->required(),
                    Forms\Components\TextInput::make('master_booking_id')
                        ->label('Any sibling booking ID (will resolve to group master)')
                        ->required()
                        ->numeric()
                        ->live(onBlur: true)
                        ->rule(function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                $b = Beds24Booking::where('beds24_booking_id', $value)->first();
                                if (! $b) {
                                    $fail("Booking #{$value} not found.");
                                    return;
                                }
                                $master = $b->master_booking_id ?? $b->beds24_booking_id;
                                if (! Beds24Booking::where('master_booking_id', $master)->exists()) {
                                    $fail("Booking #{$value} has no group siblings.");
                                }
                            };
                        }),
                    Forms\Components\Placeholder::make('group_preview')
                        ->label('Group preview')
                        ->content(function (Forms\Get $get): string {
                            $bookingId = $get('master_booking_id');
                            if (! $bookingId) return 'Enter a booking ID to preview the group.';
                            $b = Beds24Booking::where('beds24_booking_id', $bookingId)->first();
                            if (! $b) return 'Booking not found.';
                            $master = $b->master_booking_id ?? $b->beds24_booking_id;
                            $siblings = Beds24Booking::where('master_booking_id', $master)->orderBy('beds24_booking_id')->get();
                            if ($siblings->isEmpty()) return 'No siblings for this booking.';

                            $lines = ["Master: #{$master}", "Siblings ({$siblings->count()}):"];
                            $total = 0.0;
                            $currency = (string) ($siblings->first()->currency ?? 'USD');
                            foreach ($siblings as $s) {
                                $lines[] = sprintf(
                                    '  • #%s — %s — %.2f %s',
                                    $s->beds24_booking_id,
                                    $s->guest_name ?? '(no name)',
                                    (float) $s->total_amount,
                                    strtoupper((string) $s->currency),
                                );
                                $total += (float) $s->total_amount;
                            }
                            $lines[] = "Group total (sum of invoices): " . number_format($total, 2, '.', ' ') . " {$currency}";
                            return implode("\n", $lines);
                        }),
                    Forms\Components\TextInput::make('total_amount')
                        ->label('Group total')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->helperText('Sum-locked: must match group sum of invoices ±1 unit (UZS) or ±0.50 (USD/EUR).'),
                    Forms\Components\Select::make('total_currency')
                        ->label('Currency')
                        ->options(['UZS' => 'UZS', 'USD' => 'USD', 'EUR' => 'EUR'])
                        ->required()
                        ->default('USD'),
                    Forms\Components\Select::make('payment_method')
                        ->label('Method')
                        ->options(['cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(RecordBulkGroupPaymentFromAdminAction::class)->execute($data);

                        Notification::make()
                            ->title('Bulk group payment recorded')
                            ->body(sprintf(
                                'Journal %s — %d legs created, master #%s.',
                                substr($result['journal_uuid'], 0, 8) . '…',
                                count($result['transactions']),
                                $result['master_booking_id'] ?? '?',
                            ))
                            ->success()
                            ->send();
                    } catch (\App\Exceptions\GroupAlreadyPartiallyPaidException $e) {
                        $p = $e->payload();
                        Notification::make()
                            ->title('Group already partially paid')
                            ->body(
                                "Already paid: " . implode(', ', $p['already_paid_booking_ids']) . "\n"
                                . "Unpaid: " . implode(', ', $p['unpaid_booking_ids']) . "\n\n"
                                . "Bulk requires ALL siblings unpaid. Settle individual rooms via the cashier bot."
                            )
                            ->warning()
                            ->persistent()
                            ->send();
                    } catch (\App\Exceptions\GroupCompositionChangedException $e) {
                        Notification::make()
                            ->title('Group changed since preview')
                            ->body('Reload the page and try again — Beds24 group composition has changed.')
                            ->warning()
                            ->persistent()
                            ->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Sum-lock or validation failure')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Bulk group payment action failed', [
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
