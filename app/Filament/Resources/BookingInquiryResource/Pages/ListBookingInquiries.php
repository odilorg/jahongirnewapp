<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingInquiryResource\Pages;

use App\Filament\Resources\BookingInquiryResource;
use App\Models\BookingInquiry;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListBookingInquiries extends ListRecords
{
    protected static string $resource = BookingInquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('+ New booking inquiry'),
        ];
    }

    /**
     * Status-based tab navigation. "All" is first so operators can see the
     * full picture; "New" is the default working view.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(fn () => BookingInquiry::count()),

            'new' => Tab::make('New')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_NEW))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_NEW)->count())
                ->badgeColor('warning'),

            'contacted' => Tab::make('Contacted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_CONTACTED))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONTACTED)->count())
                ->badgeColor('info'),

            'awaiting_customer' => Tab::make('Awaiting customer')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_AWAITING_CUSTOMER))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_AWAITING_CUSTOMER)->count())
                ->badgeColor('primary'),

            'awaiting_payment' => Tab::make('Awaiting payment')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_AWAITING_PAYMENT))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_AWAITING_PAYMENT)->count())
                ->badgeColor('warning'),

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_CONFIRMED))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONFIRMED)->count())
                ->badgeColor('success'),

            // Ops tab: confirmed sales travelling today. This is the
            // dispatcher's working view — who leaves in the next few hours.
            'today' => Tab::make('Today')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', BookingInquiry::STATUS_CONFIRMED)
                    ->whereDate('travel_date', today()))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONFIRMED)
                    ->whereDate('travel_date', today())
                    ->count())
                ->badgeColor('success'),

            // Prep view: confirmed sales leaving tomorrow — finalise
            // driver/guide/pickup details today while there is still time.
            'tomorrow' => Tab::make('Tomorrow')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', BookingInquiry::STATUS_CONFIRMED)
                    ->whereDate('travel_date', today()->addDay()))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONFIRMED)
                    ->whereDate('travel_date', today()->addDay())
                    ->count())
                ->badgeColor('primary'),

            // Review follow-up: tours that ENDED today (Tashkent). The
            // operator scans this list each morning to decide who gets a
            // manual TripAdvisor review request. End-date is computed
            // as travel_date + (catalog duration_days - 1); fallback 1
            // day when no catalog product is linked. Cancelled rows
            // excluded; already-sent rows kept (visible review-sent column
            // shows the timestamp so operator can decide whether to resend).
            'review_followup' => Tab::make('Review follow-up')
                ->modifyQueryUsing(fn (Builder $query) => $this->scopeReviewFollowupQuery($query))
                ->badge(fn () => $this->scopeReviewFollowupQuery(BookingInquiry::query())->count())
                ->badgeColor('info'),

            // Archive: tours that have actually run to completion.
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('prep_status', BookingInquiry::PREP_COMPLETED))
                ->badge(fn () => BookingInquiry::where('prep_status', BookingInquiry::PREP_COMPLETED)->count()),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_CANCELLED)),

            'spam' => Tab::make('Spam')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_SPAM))
                ->badgeColor('danger'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'new';
    }

    /**
     * Centralised "tour ended today" scope used by the Review follow-up
     * tab. Extracted because it's needed in two places (the modify-
     * query callback + the badge counter), and the SQL deserves to live
     * in one spot for testability and future tuning.
     *
     * Logic:
     *   - status = confirmed
     *   - cancelled_at IS NULL
     *   - travel_date + (catalog duration_days − 1) = today (Asia/Tashkent)
     *   - duration falls back to 1 when no tour_product is linked
     *     (single-day tours are the dominant case)
     *
     * Bookings whose review request is already sent are intentionally
     * KEPT in the list — operators sometimes want to resend; the
     * review_request_sent_at column surfaces the prior send so they
     * can decide consciously.
     */
    private function scopeReviewFollowupQuery(Builder $query): Builder
    {
        $today = now('Asia/Tashkent')->toDateString();

        return $query
            ->where('booking_inquiries.status', BookingInquiry::STATUS_CONFIRMED)
            ->whereNull('booking_inquiries.cancelled_at')
            ->whereRaw(
                "DATE_ADD(booking_inquiries.travel_date, INTERVAL (
                    COALESCE((SELECT duration_days FROM tour_products WHERE id = booking_inquiries.tour_product_id), 1) - 1
                 ) DAY) = ?",
                [$today]
            );
    }
}
