<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\BookingInquiry;
use App\Services\DriverDispatchNotifier;
use App\Services\TourCalendarBuilder;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components;
use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;

class TourCalendar extends Page implements HasActions, HasForms, HasInfolists
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithInfolists;

    protected static ?string $navigationIcon  = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Tour Calendar';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int    $navigationSort  = 10;

    protected static string $view = 'filament.pages.tour-calendar';

    public ?string $week = null;
    public bool $showLeads = false;
    public ?int $selectedInquiryId = null;
    // Phase 20 — view mode: 'action' (dispatch board) or 'grid' (legacy week view)
    public string $viewMode = 'action';

    public function toggleViewMode(): void
    {
        $this->viewMode = $this->viewMode === 'action' ? 'grid' : 'action';
    }

    public function mount(): void
    {
        $this->week = $this->week ?? Carbon::today()->toDateString();
    }

    protected function getViewData(): array
    {
        $anchor = $this->week ? Carbon::parse($this->week) : Carbon::today();

        $statuses = [
            BookingInquiry::STATUS_CONFIRMED,
            BookingInquiry::STATUS_AWAITING_PAYMENT,
        ];

        if ($this->showLeads) {
            $statuses[] = BookingInquiry::STATUS_CONTACTED;
            $statuses[] = BookingInquiry::STATUS_AWAITING_CUSTOMER;
        }

        // Always 7 days from anchor. Mon-Sun mode created a gap when paging
        // back from the today-anchored view (Apr 19–25 → Apr 6–12, missing
        // Apr 13–18). Contiguous sliding windows are simpler and gap-free.
        $startFromAnchor = true;

        $assignedTo = $this->mineOnly ? auth()->id() : null;
        $builder    = app(TourCalendarBuilder::class);

        $viewData = [
            'data'       => $builder->buildWeek($anchor, $statuses, $startFromAnchor, $assignedTo),
            'action'     => null,
            'viewMode'   => $this->viewMode,
        ];

        // Action view data is always loaded so the summary strip works
        // on both views. Cheap query; bounded to today + 7 days.
        $viewData['action'] = $builder->buildActionView($assignedTo);

        return $viewData;
    }

    public function toggleMineOnly(): void
    {
        $this->mineOnly = ! $this->mineOnly;
    }

    public function previousWeek(): void
    {
        $this->week = Carbon::parse($this->week)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->week = Carbon::parse($this->week)->addWeek()->toDateString();
    }

    public function thisWeek(): void
    {
        // Set to today — the builder will use today as the first column
        // instead of rewinding to Monday.
        $this->week = Carbon::today()->toDateString();
    }

    public function isToday(): bool
    {
        return Carbon::parse($this->week)->isToday();
    }

    // Quick-assign properties for the slide-over form
    public ?int $assignDriverId = null;
    public ?int $assignDriverRateId = null;
    public ?int $assignGuideId = null;
    public ?int $assignGuideRateId = null;
    public ?string $editPickupTime = null;
    public ?string $editPickupPoint = null;
    public ?string $editDropoffPoint = null;
    public ?int $assignAccommodationId = null;
    public ?int $assignAccGuests = null;
    public int $assignAccNights = 1;
    public ?string $assignAccDate = null;
    public ?int $editDirectionId = null;

    // Calendar filter: show only my leads
    public bool $mineOnly = false;

    // Reassign
    public ?int $reassignUserId = null;

    // Quick price
    public ?string $editPriceQuoted = null;

    // Reminder
    public ?string $reminderRemindAt = null;
    public ?string $reminderMessage = null;

    // Guest payment
    public ?string $guestPayAmount = null;
    public string $guestPayMethod = 'cash';

    // Quick pay
    public ?string $paySupplierType = null;
    public ?int $paySupplierIdVal = null;
    public ?string $payAmount = null;
    public string $payMethod = 'cash';

    /**
     * Record a guest payment from the slide-over.
     */
    public function quickGuestPay(?string $amount = null, ?string $method = null): void
    {
        $amount = $amount ?: $this->guestPayAmount;
        $method = $method ?: $this->guestPayMethod;

        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry || ! $amount) {
            Notification::make()->title('Guest payment failed — missing amount')->danger()->send();
            return;
        }

        $inquiry->assignIfUnowned(auth()->id());

        \App\Models\GuestPayment::create([
            'booking_inquiry_id'  => $inquiry->id,
            'amount'              => (float) $amount,
            'currency'            => 'USD',
            'payment_type'        => abs((float) $amount) >= (float) ($inquiry->price_quoted ?? 0) ? 'full' : 'balance',
            'payment_method'      => $method ?: 'cash',
            'payment_date'        => now()->toDateString(),
            'recorded_by_user_id' => auth()->id(),
            'status'              => 'recorded',
        ]);

        Notification::make()->title("Guest payment recorded: \${$amount}")->success()->send();

        $this->guestPayAmount = null;
    }

    /**
     * Quick-save price from the slide-over. Recomputes commission + net revenue.
     */
    public function quickSavePrice(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry || $this->editPriceQuoted === null || $this->editPriceQuoted === '') {
            return;
        }

        $inquiry->assignIfUnowned(auth()->id());

        $gross = (float) $this->editPriceQuoted;
        $updates = ['price_quoted' => $gross];

        // Recompute commission if OTA source has a configured rate
        $rate = (float) ($inquiry->commission_rate ?? 0);
        if ($rate > 0) {
            $commission = round($gross * $rate / 100, 2);
            $updates['commission_amount'] = $commission;
            // Only update net_revenue if not already overridden (null)
            if ($inquiry->net_revenue === null) {
                // keep null to let effectiveRevenue() fall back
            }
        }

        $inquiry->update($updates);

        Notification::make()->title("Price saved: \${$gross}")->success()->send();
    }

    /**
     * Phase 21 — create a reminder linked to this inquiry.
     */
    public function createReminder(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry || ! $this->reminderRemindAt || ! $this->reminderMessage) {
            return;
        }

        \App\Models\InquiryReminder::create([
            'booking_inquiry_id'  => $inquiry->id,
            'remind_at'           => $this->reminderRemindAt,
            'message'             => $this->reminderMessage,
            'created_by_user_id'  => auth()->id(),
            'assigned_to_user_id' => auth()->id(),
            'status'              => 'pending',
        ]);

        Notification::make()
            ->title('Reminder set')
            ->body('You will be reminded: ' . \Carbon\Carbon::parse($this->reminderRemindAt)->format('M j, H:i'))
            ->success()
            ->send();

        $this->reminderRemindAt = null;
        $this->reminderMessage = null;
    }

    /**
     * Preset a reminder date: +1 day, +3 days, +1 week, day before travel.
     */
    public function reminderPreset(string $preset): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry) return;

        $this->reminderRemindAt = match ($preset) {
            '1d'     => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i'),
            '3d'     => now()->addDays(3)->setTime(10, 0)->format('Y-m-d H:i'),
            '1w'     => now()->addWeek()->setTime(10, 0)->format('Y-m-d H:i'),
            'pre'    => $inquiry->travel_date
                ? $inquiry->travel_date->copy()->subDays(2)->setTime(10, 0)->format('Y-m-d H:i')
                : null,
            default  => null,
        };
    }

    public function markReminderDone(int $reminderId): void
    {
        $r = \App\Models\InquiryReminder::find($reminderId);
        if (! $r) return;

        $r->update([
            'status'               => 'done',
            'completed_at'         => now(),
            'completed_by_user_id' => auth()->id(),
        ]);

        Notification::make()->title('Reminder marked done')->success()->send();
    }

    public function snoozeReminder(int $reminderId, int $days = 1): void
    {
        $r = \App\Models\InquiryReminder::find($reminderId);
        if (! $r) return;

        $r->update([
            'remind_at'   => $r->remind_at->addDays($days),
            'notified_at' => null,
        ]);

        Notification::make()->title("Snoozed {$days} day(s)")->success()->send();
    }

    /**
     * Claim an unassigned lead.
     */
    public function claimInquiry(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry || $inquiry->assigned_to_user_id) {
            return;
        }

        $inquiry->update(['assigned_to_user_id' => auth()->id()]);
        Notification::make()->title('Lead claimed')->success()->send();
    }

    /**
     * Reassign an inquiry to another operator.
     */
    public function reassignInquiry(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry) {
            return;
        }

        $inquiry->update(['assigned_to_user_id' => $this->reassignUserId ?: null]);

        $name = $this->reassignUserId
            ? (\App\Models\User::find($this->reassignUserId)?->name ?? 'user')
            : 'unassigned';

        Notification::make()->title("Reassigned to {$name}")->success()->send();
        $this->reassignUserId = null;
    }

    /**
     * Quick-assign driver + rate from the slide-over.
     */
    public function quickAssignDriver(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        $driver  = $this->assignDriverId ? \App\Models\Driver::find($this->assignDriverId) : null;
        if (! $inquiry || ! $driver) {
            return;
        }

        $this->runCalendarAction(
            app(\App\Actions\Calendar\Assign\QuickAssignDriverAction::class)->handle(
                $inquiry,
                $driver,
                [
                    'rate_id'     => $this->assignDriverRateId,
                    'operator_id' => auth()->id(),
                ],
            ),
        );

        $this->assignDriverId = null;
        $this->assignDriverRateId = null;
    }

    /**
     * Quick-assign guide + rate from the slide-over.
     */
    public function quickAssignGuide(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        $guide   = $this->assignGuideId ? \App\Models\Guide::find($this->assignGuideId) : null;
        if (! $inquiry || ! $guide) {
            return;
        }

        $this->runCalendarAction(
            app(\App\Actions\Calendar\Assign\QuickAssignGuideAction::class)->handle(
                $inquiry,
                $guide,
                [
                    'rate_id'     => $this->assignGuideRateId,
                    'operator_id' => auth()->id(),
                ],
            ),
        );

        $this->assignGuideId = null;
        $this->assignGuideRateId = null;
    }

    /**
     * Quick-add an accommodation stay from the slide-over.
     */
    public function quickAddStay(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry || ! $this->assignAccommodationId) {
            return;
        }

        $this->runCalendarAction(
            app(\App\Actions\Calendar\Save\QuickAddStayAction::class)->handle(
                $inquiry,
                [
                    'accommodation_id' => $this->assignAccommodationId,
                    'guests'           => $this->assignAccGuests,
                    'nights'           => $this->assignAccNights,
                    'date'             => $this->assignAccDate,
                    'operator_id'      => auth()->id(),
                ],
            ),
        );

        $this->assignAccommodationId = null;
        $this->assignAccGuests = null;
        $this->assignAccNights = 1;
        $this->assignAccDate = null;
    }

    /**
     * Record a supplier payment from the slide-over.
     */
    /**
     * Phase 22 fix — accept params directly. Previous implementation used
     * x-init="$wire.set(...)" calls which fired async; if operator clicked
     * Confirm before those set requests completed, quickPay ran with null
     * supplier info and silently failed.
     */
    public function quickPay(?string $supplierType = null, ?int $supplierId = null, ?string $amount = null): void
    {
        $supplierType = $supplierType ?: $this->paySupplierType;
        $supplierId   = $supplierId   ?: $this->paySupplierIdVal;
        $amount       = $amount       ?: $this->payAmount;

        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry || ! $supplierType || ! $supplierId || ! $amount) {
            Notification::make()->title('Payment failed — missing data')->danger()->send();
            return;
        }

        // Assign locally for the rest of the method
        $this->paySupplierType = $supplierType;
        $this->paySupplierIdVal = $supplierId;
        $this->payAmount = $amount;

        $inquiry->assignIfUnowned(auth()->id());

        \App\Models\SupplierPayment::create([
            'supplier_type'      => $this->paySupplierType,
            'supplier_id'        => $this->paySupplierIdVal,
            'booking_inquiry_id' => $inquiry->id,
            'amount'             => (float) $this->payAmount,
            'currency'           => 'USD',
            'payment_date'       => now()->toDateString(),
            'payment_method'     => $this->payMethod,
            'status'             => 'recorded',
        ]);

        $name = match ($this->paySupplierType) {
            'driver'        => $inquiry->driver?->full_name,
            'guide'         => $inquiry->guide?->full_name,
            'accommodation' => $inquiry->stays->first()?->accommodation?->name,
            default         => 'supplier',
        };

        Notification::make()
            ->title("Paid \${$this->payAmount} to {$name}")
            ->success()
            ->send();

        // Reset
        $this->paySupplierType = null;
        $this->paySupplierIdVal = null;
        $this->payAmount = null;
        $this->payMethod = 'cash';
    }

    /**
     * Quick-save direction from the slide-over.
     */
    public function quickSaveDirection(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry) {
            return;
        }

        $this->runCalendarAction(
            app(\App\Actions\Calendar\Save\QuickSaveDirectionAction::class)->handle(
                $inquiry,
                [
                    'direction_id' => $this->editDirectionId,
                    'operator_id'  => auth()->id(),
                ],
            ),
        );
    }

    /**
     * Quick-save pickup time + location from the slide-over.
     */
    public function quickSavePickup(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry) {
            return;
        }

        $this->runCalendarAction(
            app(\App\Actions\Calendar\Save\QuickSavePickupAction::class)->handle(
                $inquiry,
                [
                    'time'        => $this->editPickupTime,
                    'point'       => $this->editPickupPoint,
                    'operator_id' => auth()->id(),
                ],
            ),
        );
    }

    /**
     * Quick-save drop-off location from the slide-over.
     * Location-only by design — dropoff time is implicit (end of tour)
     * and no dropoff_time column exists.
     */
    public function quickSaveDropoff(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry) {
            return;
        }

        $this->runCalendarAction(
            app(\App\Actions\Calendar\Save\QuickSaveDropoffAction::class)->handle(
                $inquiry,
                [
                    'point'       => $this->editDropoffPoint,
                    'operator_id' => auth()->id(),
                ],
            ),
        );
    }

    /**
     * Called from Blade when a chip is clicked. Opens the slide-over.
     */
    public function openInquiry(int $id): void
    {
        $this->selectedInquiryId = $id;

        // Pre-fill fields from the inquiry
        $inquiry = BookingInquiry::find($id);
        $this->editPickupTime    = $inquiry?->pickup_time;
        $this->editPickupPoint   = $inquiry?->pickup_point;
        $this->editDropoffPoint  = $inquiry?->dropoff_point;
        $this->assignDriverId    = $inquiry?->driver_id;
        $this->assignDriverRateId = $inquiry?->driver_rate_id;
        $this->assignGuideId     = $inquiry?->guide_id;
        $this->assignGuideRateId = $inquiry?->guide_rate_id;
        $this->editDirectionId   = $inquiry?->tour_product_direction_id;
        $this->reassignUserId    = $inquiry?->assigned_to_user_id;
        $this->editPriceQuoted   = $inquiry?->price_quoted ? (string) $inquiry->price_quoted : null;
        // Smart default reminder: day before travel at 10:00, else +1 day
        $this->reminderRemindAt  = $inquiry?->travel_date
            ? $inquiry->travel_date->copy()->subDay()->setTime(10, 0)->format('Y-m-d H:i')
            : now()->addDay()->setTime(10, 0)->format('Y-m-d H:i');
        $this->reminderMessage   = null;

        // Smart default: if yurt camp tour + no stay yet, pre-select Aydarkul
        $hasStays = $inquiry?->stays()->exists();
        $isYurt   = $inquiry?->tour_slug === 'yurt-camp-tour';
        $this->assignAccommodationId = (! $hasStays && $isYurt)
            ? \App\Models\Accommodation::where('name', 'like', '%Aydarkul%')->value('id')
            : null;
        $this->assignAccGuests   = $inquiry?->people_adults;
        $this->assignAccNights   = 1;
        $this->assignAccDate     = $inquiry?->travel_date?->toDateString();

        $this->mountAction('viewInquiry');
    }

    /**
     * Slide-over action that shows inquiry details + operational actions.
     */
    public function viewInquiryAction(): Action
    {
        return Action::make('viewInquiry')
            ->slideOver()
            ->modalWidth('lg')
            ->modalHeading(fn (): string => $this->getSelectedInquiry()?->reference ?? 'Inquiry')
            ->modalContent(fn (): \Illuminate\Contracts\View\View => view(
                'filament.pages.tour-calendar-slideover',
                [
                    'inquiry'               => $this->getSelectedInquiry(),
                    'assignDriverId'        => $this->assignDriverId,
                    'assignDriverRateId'    => $this->assignDriverRateId,
                    'assignGuideId'         => $this->assignGuideId,
                    'assignGuideRateId'     => $this->assignGuideRateId,
                    'assignAccommodationId' => $this->assignAccommodationId,
                    'assignAccGuests'       => $this->assignAccGuests,
                    'assignAccNights'       => $this->assignAccNights,
                ],
            ))
            ->modalFooterActions(fn (): array => $this->getSlideOverActions())
            ->closeModalByClickingAway()
            ->modalCancelAction(false);
    }

    /**
     * @return array<Action>
     */
    private function getSlideOverActions(): array
    {
        $inquiry = $this->getSelectedInquiry();
        if (! $inquiry) {
            return [];
        }

        $actions = [];

        // WhatsApp guest
        $waPhone = preg_replace('/[^0-9]/', '', (string) $inquiry->customer_phone);
        if ($waPhone) {
            $actions[] = Action::make('whatsappGuest')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->url("https://wa.me/{$waPhone}", shouldOpenInNewTab: true);
        }

        // Email guest
        if (filled($inquiry->customer_email)) {
            $subject = rawurlencode("Your {$inquiry->tour_name_snapshot} — {$inquiry->reference}");
            $actions[] = Action::make('emailGuest')
                ->label('Email')
                ->icon('heroicon-o-envelope')
                ->color('info')
                ->url("mailto:{$inquiry->customer_email}?subject={$subject}", shouldOpenInNewTab: true);
        }

        // Dispatch gate — uses BookingInquiry::isDispatchable() so this stays
        // in lockstep with the assign UIs (slide-over + resource form).
        $dispatchable = $inquiry->isDispatchable();

        // Dispatch state helper: never-dispatched / clean / stale.
        $dispatchState = function (?\Carbon\Carbon $dispatchedAt) use ($inquiry): string {
            if (! $dispatchedAt) return 'fresh';
            return $inquiry->updated_at && $inquiry->updated_at->gt($dispatchedAt) ? 'stale' : 'clean';
        };

        if ($inquiry->driver_id && $dispatchable) {
            $state = $dispatchState($inquiry->driver_dispatched_at);
            $actions[] = Action::make('dispatchDriver')
                ->label(match ($state) {
                    'fresh' => 'Dispatch driver',
                    'clean' => 'Re-dispatch driver (already sent ' . $inquiry->driver_dispatched_at->format('M j H:i') . ')',
                    'stale' => 'Re-dispatch driver — changes since ' . $inquiry->driver_dispatched_at->format('M j H:i'),
                })
                ->icon('heroicon-o-truck')
                ->color(match ($state) {
                    'fresh' => 'primary',
                    'clean' => 'gray',
                    'stale' => 'danger',
                })
                ->requiresConfirmation()
                ->modalDescription('Send Telegram DM to: 🚗 ' . ($inquiry->driver?->full_name ?? '—'))
                ->action(fn () => $this->runCalendarAction(
                    app(\App\Actions\Calendar\Dispatch\DispatchDriverAction::class)->handle($inquiry),
                ));
        }

        // Dispatch guide
        if ($inquiry->guide_id && $dispatchable) {
            $state = $dispatchState($inquiry->guide_dispatched_at);
            $actions[] = Action::make('dispatchGuide')
                ->label(match ($state) {
                    'fresh' => 'Dispatch guide',
                    'clean' => 'Re-dispatch guide (already sent ' . $inquiry->guide_dispatched_at->format('M j H:i') . ')',
                    'stale' => 'Re-dispatch guide — changes since ' . $inquiry->guide_dispatched_at->format('M j H:i'),
                })
                ->icon('heroicon-o-academic-cap')
                ->color(match ($state) {
                    'fresh' => 'primary',
                    'clean' => 'gray',
                    'stale' => 'danger',
                })
                ->requiresConfirmation()
                ->modalDescription('Send Telegram DM to: 🧭 ' . ($inquiry->guide?->full_name ?? '—'))
                ->action(fn () => $this->runCalendarAction(
                    app(\App\Actions\Calendar\Dispatch\DispatchGuideAction::class)->handle($inquiry),
                ));
        }

        // Dispatch accommodation stays. Label reflects aggregate stay state:
        // - fresh: at least one stay has never been dispatched
        // - stale: every stay dispatched, but operator edited at least one after dispatch
        // - clean: every stay dispatched and none edited since
        if ($inquiry->stays->isNotEmpty() && $dispatchable) {
            $stays = $inquiry->stays;
            $anyUndispatched = $stays->contains(fn ($s) => ! $s->dispatched_at);
            $anyStale = $stays->contains(fn ($s) => $s->dispatched_at && $s->updated_at->gt($s->dispatched_at));
            $aggState = $anyUndispatched ? 'fresh' : ($anyStale ? 'stale' : 'clean');

            $actions[] = Action::make('dispatchAccommodation')
                ->label(match ($aggState) {
                    'fresh' => 'Dispatch accommodation' . ($stays->count() > 1 ? ' (' . $stays->count() . ')' : ''),
                    'clean' => 'Re-dispatch accommodation (all sent)',
                    'stale' => 'Re-dispatch accommodation — changes since dispatch',
                })
                ->icon('heroicon-o-home-modern')
                ->color(match ($aggState) {
                    'fresh' => 'primary',
                    'clean' => 'gray',
                    'stale' => 'danger',
                })
                ->requiresConfirmation()
                ->modalDescription(function () use ($inquiry): string {
                    return $inquiry->stays
                        ->map(fn ($s) => '🏕 ' . ($s->accommodation?->name ?? '—') . ' · ' . $s->stay_date?->format('M j'))
                        ->implode("\n");
                })
                ->action(fn () => $this->runCalendarAction(
                    app(\App\Actions\Calendar\Dispatch\DispatchAccommodationAction::class)->handle($inquiry),
                ));
        }

        // Open full inquiry pages
        $actions[] = Action::make('editFull')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->url(\App\Filament\Resources\BookingInquiryResource::getUrl('edit', ['record' => $inquiry->id]), shouldOpenInNewTab: true);

        $actions[] = Action::make('openFull')
            ->label('View')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(\App\Filament\Resources\BookingInquiryResource::getUrl('view', ['record' => $inquiry->id]), shouldOpenInNewTab: true);

        return $actions;
    }

    private function getSelectedInquiry(): ?BookingInquiry
    {
        if (! $this->selectedInquiryId) {
            return null;
        }

        return BookingInquiry::with(['driver', 'guide', 'tourProduct', 'stays.accommodation'])
            ->find($this->selectedInquiryId);
    }

    /**
     * Translate a framework-agnostic CalendarActionResult into the Filament
     * notification UX the page uses everywhere. Single conversion point so
     * Actions stay pure and the presentation rule lives in the page.
     */
    private function runCalendarAction(\App\Actions\Calendar\Support\CalendarActionResult $result): void
    {
        if ($result->success) {
            Notification::make()
                ->title($result->message ?? 'Done')
                ->success()
                ->send();
            return;
        }

        Notification::make()
            ->title($result->message ?? 'Action failed')
            ->danger()
            ->persistent()
            ->send();
    }

    public static function getNavigationLabel(): string
    {
        return 'Tour Calendar';
    }

    public function getTitle(): string
    {
        return 'Tour Calendar';
    }
}
