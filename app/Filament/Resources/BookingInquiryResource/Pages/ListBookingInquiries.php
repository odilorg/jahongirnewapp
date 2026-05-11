<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingInquiryResource\Pages;

use App\Filament\Resources\BookingInquiryResource;
use App\Models\BookingInquiry;
use Filament\Actions;
use Filament\Notifications\Notification;
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
                ->label('+ New booking inquiry')
                // Block silent duplicate creation when an in-flight inquiry
                // already exists for the same phone/email. Reason:
                // feedback_octo_cancel_duplicate_inquiry_risk (2026-05-11
                // Imene incident — orphan #113 created instead of using
                // markPaidOffline on #112). before() halts the save and
                // surfaces the existing refs so operator can navigate to them.
                ->before(fn (Actions\CreateAction $action, array $data) => $this->blockIfDuplicateExists($action, $data)),
        ];
    }

    /**
     * Halt CreateAction with an informative notification when an in-flight
     * inquiry already exists for the same normalized phone or email.
     *
     * Matching logic lives on the model (BookingInquiry::findInFlightDuplicates).
     * "In-flight" = status new/contacted/awaiting_customer/awaiting_payment.
     * Confirmed/cancelled/spam/completed don't block (returning customers
     * legitimately need new inquiries).
     */
    private function blockIfDuplicateExists(Actions\CreateAction $action, array $data): void
    {
        $matches = BookingInquiry::findInFlightDuplicates(
            $data['customer_phone'] ?? null,
            $data['customer_email'] ?? null,
        );

        if ($matches->isEmpty()) {
            return;
        }

        $lines = $matches->map(fn (BookingInquiry $b) => sprintf(
            '#%d  %s  · status=%s  · %s',
            $b->id,
            $b->reference,
            $b->status,
            $b->customer_name ?: '(no name)',
        ))->all();

        Notification::make()
            ->title('Possible duplicate — creation blocked')
            ->body(
                "An in-flight inquiry already exists for this contact:\n\n"
                .implode("\n", $lines)
                ."\n\nOpen the existing inquiry instead. If you need to record an offline payment, use the row's “Mark paid (cash / card)” action."
            )
            ->danger()
            ->persistent()
            ->send();

        $action->halt();
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

            // Payment dropped: an Octo attempt happened but the payment never
            // captured (cancelled/declined/expired) AND no offline payment has
            // been recorded since. These rows are the highest-risk-of-orphan-
            // duplicate state — surfacing them here is the operational
            // counterpart to the CreateAction duplicate-block.
            //
            // Filter: paid_at IS NULL AND payment_link IS NULL AND
            //         octo_transaction_id IS NOT NULL AND
            //         status IN (contacted, awaiting_payment).
            //
            // octo_transaction_id is stamped by OctoCallbackController on both
            // attempt-first (Phase 1+) and direct (pre-Phase-1) callback paths
            // so a single column check covers both eras.
            'payment_dropped' => Tab::make('Payment dropped')
                ->modifyQueryUsing(fn (Builder $query) => $this->scopePaymentDroppedQuery($query))
                ->badge(fn () => $this->scopePaymentDroppedQuery(BookingInquiry::query())->count())
                ->badgeColor('danger'),

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
                'DATE_ADD(booking_inquiries.travel_date, INTERVAL (
                    COALESCE((SELECT duration_days FROM tour_products WHERE id = booking_inquiries.tour_product_id), 1) - 1
                 ) DAY) = ?',
                [$today]
            );
    }

    /**
     * "Payment dropped" scope — Octo attempt happened but the payment never
     * captured AND no offline payment has been recorded.
     *
     * Used by the Payment-dropped tab modifier and badge counter, both of
     * which need the identical filter. See feedback_octo_cancel_duplicate_inquiry_risk
     * for the operational rationale.
     */
    private function scopePaymentDroppedQuery(Builder $query): Builder
    {
        return $query
            ->whereNull('paid_at')
            ->whereNull('payment_link')
            ->whereNotNull('octo_transaction_id')
            ->whereIn('status', [
                BookingInquiry::STATUS_CONTACTED,
                BookingInquiry::STATUS_AWAITING_PAYMENT,
            ]);
    }
}
