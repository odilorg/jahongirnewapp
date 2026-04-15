<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\BookingInquiry;
use App\Services\TourCalendarBuilder;
use Carbon\Carbon;
use Filament\Pages\Page;

/**
 * Tour Calendar — week view, tours as rows, days as columns.
 *
 * Rows = distinct tour products (grouped by tour_slug)
 * Columns = Mon..Sun in the visible window
 * Cells = clickable booking chips on the tour's travel_date
 *
 * Read-only in v1. Phase 7.1 will add multi-day chip spanning and
 * drag-to-reschedule.
 */
class TourCalendar extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Tour Calendar';
    protected static ?string $navigationGroup = 'Tours';
    protected static ?int    $navigationSort  = 1;

    protected static string $view = 'filament.pages.tour-calendar';

    public ?string $week = null;

    public bool $includeAwaitingPayment = false;

    public function mount(): void
    {
        $this->week = $this->week ?? Carbon::today()->toDateString();
    }

    protected function getViewData(): array
    {
        $anchor = $this->week ? Carbon::parse($this->week) : Carbon::today();

        $statuses = ['confirmed'];
        if ($this->includeAwaitingPayment) {
            $statuses[] = BookingInquiry::STATUS_AWAITING_PAYMENT;
        }

        return [
            'data' => app(TourCalendarBuilder::class)->buildWeek($anchor, $statuses),
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
        $this->week = Carbon::today()->toDateString();
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
