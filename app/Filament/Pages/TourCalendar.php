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
    protected static ?string $navigationGroup = 'Tours';
    protected static ?int    $navigationSort  = -10;

    protected static string $view = 'filament.pages.tour-calendar';

    public ?string $week = null;
    public bool $showLeads = false;
    public ?int $selectedInquiryId = null;

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

        $startFromAnchor = $anchor->isToday();

        return [
            'data' => app(TourCalendarBuilder::class)->buildWeek($anchor, $statuses, $startFromAnchor),
        ];
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

    /**
     * Quick-assign driver + rate from the slide-over.
     */
    public function quickAssignDriver(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry || ! $this->assignDriverId) {
            return;
        }

        $update = ['driver_id' => $this->assignDriverId];

        if ($this->assignDriverRateId) {
            $rate = \App\Models\DriverRate::find($this->assignDriverRateId);
            if ($rate) {
                $update['driver_rate_id'] = $rate->id;
                $update['driver_cost']    = $rate->cost_usd;
            }
        }

        $inquiry->update($update);

        Notification::make()->title('Driver assigned')->success()->send();

        // Reset for next assignment
        $this->assignDriverId = null;
        $this->assignDriverRateId = null;
    }

    /**
     * Quick-assign guide + rate from the slide-over.
     */
    public function quickAssignGuide(): void
    {
        $inquiry = BookingInquiry::find($this->selectedInquiryId);
        if (! $inquiry || ! $this->assignGuideId) {
            return;
        }

        $update = ['guide_id' => $this->assignGuideId];

        if ($this->assignGuideRateId) {
            $rate = \App\Models\GuideRate::find($this->assignGuideRateId);
            if ($rate) {
                $update['guide_rate_id'] = $rate->id;
                $update['guide_cost']    = $rate->cost_usd;
            }
        }

        $inquiry->update($update);

        Notification::make()->title('Guide assigned')->success()->send();

        $this->assignGuideId = null;
        $this->assignGuideRateId = null;
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

        $inquiry->update([
            'pickup_time'  => $this->editPickupTime ?: null,
            'pickup_point' => $this->editPickupPoint ?: null,
        ]);

        Notification::make()->title('Pickup info saved')->success()->send();
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
        $this->assignDriverId    = $inquiry?->driver_id;
        $this->assignDriverRateId = $inquiry?->driver_rate_id;
        $this->assignGuideId     = $inquiry?->guide_id;
        $this->assignGuideRateId = $inquiry?->guide_rate_id;

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
                ['inquiry' => $this->getSelectedInquiry()],
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
                ->label('WhatsApp guest')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->url("https://wa.me/{$waPhone}", shouldOpenInNewTab: true);
        }

        // Dispatch driver
        if ($inquiry->driver_id && $inquiry->status === BookingInquiry::STATUS_CONFIRMED) {
            $actions[] = Action::make('dispatchDriver')
                ->label('Dispatch driver')
                ->icon('heroicon-o-truck')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Send Telegram DM to: 🚗 ' . ($inquiry->driver?->full_name ?? '—'))
                ->action(function () use ($inquiry): void {
                    $result = app(DriverDispatchNotifier::class)->dispatchSupplier($inquiry, 'driver');
                    $stamp = now()->format('Y-m-d H:i');
                    $name = $inquiry->driver?->full_name ?? 'driver';

                    if ($result['ok']) {
                        $inquiry->update([
                            'internal_notes' => ($inquiry->internal_notes ? $inquiry->internal_notes . "\n" : '')
                                . "[{$stamp}] Calendar dispatch TG → driver {$name} ok (msg_id={$result['msg_id']})",
                        ]);
                        Notification::make()->title('Driver dispatch sent')->success()->send();
                    } else {
                        $reason = $result['reason'] ?? 'unknown';
                        Notification::make()->title('Driver dispatch failed')->body($reason)->danger()->persistent()->send();
                    }
                });
        }

        // Dispatch guide
        if ($inquiry->guide_id && $inquiry->status === BookingInquiry::STATUS_CONFIRMED) {
            $actions[] = Action::make('dispatchGuide')
                ->label('Dispatch guide')
                ->icon('heroicon-o-academic-cap')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Send Telegram DM to: 🧭 ' . ($inquiry->guide?->full_name ?? '—'))
                ->action(function () use ($inquiry): void {
                    $result = app(DriverDispatchNotifier::class)->dispatchSupplier($inquiry, 'guide');
                    $stamp = now()->format('Y-m-d H:i');
                    $name = $inquiry->guide?->full_name ?? 'guide';

                    if ($result['ok']) {
                        $inquiry->update([
                            'internal_notes' => ($inquiry->internal_notes ? $inquiry->internal_notes . "\n" : '')
                                . "[{$stamp}] Calendar dispatch TG → guide {$name} ok (msg_id={$result['msg_id']})",
                        ]);
                        Notification::make()->title('Guide dispatch sent')->success()->send();
                    } else {
                        $reason = $result['reason'] ?? 'unknown';
                        Notification::make()->title('Guide dispatch failed')->body($reason)->danger()->persistent()->send();
                    }
                });
        }

        // Open full inquiry pages
        $actions[] = Action::make('editFull')
            ->label('Edit booking')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->url(\App\Filament\Resources\BookingInquiryResource::getUrl('edit', ['record' => $inquiry->id]), shouldOpenInNewTab: true);

        $actions[] = Action::make('openFull')
            ->label('View details')
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

    public static function getNavigationLabel(): string
    {
        return 'Tour Calendar';
    }

    public function getTitle(): string
    {
        return 'Tour Calendar';
    }
}
