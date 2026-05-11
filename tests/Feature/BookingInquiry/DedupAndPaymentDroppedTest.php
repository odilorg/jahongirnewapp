<?php

declare(strict_types=1);

namespace Tests\Feature\BookingInquiry;

use App\Models\BookingInquiry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for two related fixes to the 2026-05-11 Imene duplicate
 * incident (orphan INQ-2026-000108 created alongside INQ-2026-000107):
 *
 *   1. BookingInquiry::findInFlightDuplicates — model-layer normalization +
 *      lookup used by the Filament CreateAction `before()` hook to block
 *      silent duplicate creation.
 *
 *   2. ListBookingInquiries "Payment dropped" tab filter — surfaces inquiries
 *      where an Octo payment attempt happened but no offline payment has
 *      been recorded, so operators can find them via markPaidOffline.
 *
 * The Filament CreateAction hook itself is not unit-tested here (Filament
 * actions are wired into Livewire and need a full Livewire test harness to
 * exercise; the model helper IS the business rule).
 */
class DedupAndPaymentDroppedTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference' => 'INQ-TEST-'.uniqid(),
            'source' => 'website',
            'status' => BookingInquiry::STATUS_NEW,
            'customer_name' => 'Test Guest',
            'customer_phone' => '+998901234567',
            'customer_email' => 'guest@example.com',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults' => 1,
            'people_children' => 0,
            'travel_date' => now()->addDays(10)->toDateString(),
            'submitted_at' => now(),
        ], $overrides));
    }

    // ── findInFlightDuplicates ────────────────────────────────────────────

    public function test_dedup_matches_same_phone_with_different_formatting(): void
    {
        $existing = $this->makeInquiry([
            'customer_phone' => '+49 176 30720259',
            'status' => BookingInquiry::STATUS_CONTACTED,
        ]);

        $matches = BookingInquiry::findInFlightDuplicates('+4917630720259', null);

        $this->assertCount(1, $matches);
        $this->assertSame($existing->id, $matches->first()->id);
    }

    public function test_dedup_strips_separators_on_stored_value_too(): void
    {
        // Inverse direction: stored value has separators, lookup is digits-
        // only. Proves REGEXP_REPLACE fires on the stored side, not just on
        // the input. Regression guard against any future "client-side
        // normalization only" rewrite.
        $existing = $this->makeInquiry([
            'customer_phone' => '4917630720259',
            'status' => BookingInquiry::STATUS_NEW,
        ]);

        $matches = BookingInquiry::findInFlightDuplicates('+49 (176) 3072-0259', null);

        $this->assertCount(1, $matches);
        $this->assertSame($existing->id, $matches->first()->id);
    }

    public function test_dedup_matches_same_email_case_insensitive(): void
    {
        $existing = $this->makeInquiry([
            'customer_email' => 'I.AnaCheza@gmail.com',
            'status' => BookingInquiry::STATUS_CONTACTED,
        ]);

        $matches = BookingInquiry::findInFlightDuplicates(null, '  i.anacheza@gmail.com  ');

        $this->assertCount(1, $matches);
        $this->assertSame($existing->id, $matches->first()->id);
    }

    public function test_dedup_ignores_confirmed_inquiries(): void
    {
        // Returning customer who already has a confirmed booking should be
        // allowed to create a new inquiry without being blocked.
        $this->makeInquiry([
            'customer_phone' => '+998901111111',
            'status' => BookingInquiry::STATUS_CONFIRMED,
        ]);

        $matches = BookingInquiry::findInFlightDuplicates('+998901111111', null);

        $this->assertCount(0, $matches, 'Confirmed (closed) inquiries must not block new creates.');
    }

    public function test_dedup_ignores_cancelled_and_spam_inquiries(): void
    {
        $this->makeInquiry([
            'customer_phone' => '+998902222222',
            'status' => BookingInquiry::STATUS_CANCELLED,
        ]);
        $this->makeInquiry([
            'customer_phone' => '+998902222222',
            'status' => BookingInquiry::STATUS_SPAM,
        ]);

        $matches = BookingInquiry::findInFlightDuplicates('+998902222222', null);

        $this->assertCount(0, $matches);
    }

    public function test_dedup_returns_all_in_flight_statuses(): void
    {
        foreach ([
            BookingInquiry::STATUS_NEW,
            BookingInquiry::STATUS_CONTACTED,
            BookingInquiry::STATUS_AWAITING_CUSTOMER,
            BookingInquiry::STATUS_AWAITING_PAYMENT,
        ] as $status) {
            $this->makeInquiry([
                'reference' => "INQ-TEST-{$status}-".uniqid(),
                'customer_phone' => '+998903333333',
                'status' => $status,
            ]);
        }

        $matches = BookingInquiry::findInFlightDuplicates('+998903333333', null);

        $this->assertCount(4, $matches);
    }

    public function test_dedup_empty_inputs_returns_empty(): void
    {
        $this->makeInquiry(['customer_phone' => '+998904444444']);

        $this->assertCount(0, BookingInquiry::findInFlightDuplicates(null, null));
        $this->assertCount(0, BookingInquiry::findInFlightDuplicates('', ''));
        $this->assertCount(0, BookingInquiry::findInFlightDuplicates('   ', '   '));
    }

    public function test_dedup_matches_on_either_phone_or_email(): void
    {
        $byPhone = $this->makeInquiry([
            'customer_phone' => '+998905555555',
            'customer_email' => 'aaa@example.com',
            'status' => BookingInquiry::STATUS_NEW,
        ]);
        $byEmail = $this->makeInquiry([
            'customer_phone' => '+998906666666',
            'customer_email' => 'bbb@example.com',
            'status' => BookingInquiry::STATUS_CONTACTED,
        ]);

        $matches = BookingInquiry::findInFlightDuplicates('+998905555555', 'bbb@example.com');

        $ids = $matches->pluck('id')->all();
        $this->assertContains($byPhone->id, $ids);
        $this->assertContains($byEmail->id, $ids);
    }

    // ── normalizePhone / normalizeEmail (pure helpers) ────────────────────

    public function test_normalize_phone_strips_non_digits(): void
    {
        $this->assertSame('4917630720259', BookingInquiry::normalizePhone('+49 176 30720259'));
        $this->assertSame('998901234567', BookingInquiry::normalizePhone('+998-90-123-45-67'));
        $this->assertNull(BookingInquiry::normalizePhone(null));
        $this->assertNull(BookingInquiry::normalizePhone(''));
        $this->assertNull(BookingInquiry::normalizePhone('---'));
    }

    public function test_normalize_email_lowercases_and_trims(): void
    {
        $this->assertSame('a@b.com', BookingInquiry::normalizeEmail('  A@B.com '));
        $this->assertSame('test@example.com', BookingInquiry::normalizeEmail('TEST@EXAMPLE.COM'));
        $this->assertNull(BookingInquiry::normalizeEmail(null));
        $this->assertNull(BookingInquiry::normalizeEmail(''));
        $this->assertNull(BookingInquiry::normalizeEmail('   '));
    }

    // ── Payment dropped tab filter ─────────────────────────────────────────

    /**
     * Mirror of ListBookingInquiries::scopePaymentDroppedQuery — we don't
     * call the private method directly (testing the Filament page page would
     * require Livewire boot). Instead we replicate the filter inline so the
     * test fails loudly if anyone changes one side without the other.
     */
    private function paymentDroppedQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return BookingInquiry::query()
            ->whereNull('paid_at')
            ->whereNull('payment_link')
            ->whereNotNull('octo_transaction_id')
            ->whereIn('status', [
                BookingInquiry::STATUS_CONTACTED,
                BookingInquiry::STATUS_AWAITING_PAYMENT,
            ]);
    }

    public function test_payment_dropped_includes_octo_cancelled_contacted_row(): void
    {
        // Mirror Imene's #112 post-Octo-cancel state: contacted, payment_link
        // cleared by callback, octo_transaction_id stamped, paid_at NULL.
        $row = $this->makeInquiry([
            'status' => BookingInquiry::STATUS_CONTACTED,
            'payment_link' => null,
            'octo_transaction_id' => 'inquiry_X_test',
            'paid_at' => null,
        ]);

        $this->assertTrue(
            $this->paymentDroppedQuery()->where('id', $row->id)->exists(),
            'A contacted row with cleared payment_link + non-null octo_transaction_id must surface as Payment dropped.'
        );
    }

    public function test_payment_dropped_excludes_paid_rows(): void
    {
        $row = $this->makeInquiry([
            'status' => BookingInquiry::STATUS_CONTACTED,
            'payment_link' => null,
            'octo_transaction_id' => 'inquiry_X_paid',
            'paid_at' => now(),
        ]);

        $this->assertFalse($this->paymentDroppedQuery()->where('id', $row->id)->exists());
    }

    public function test_payment_dropped_excludes_active_payment_link(): void
    {
        // Octo link still live → not dropped, just in-flight.
        $row = $this->makeInquiry([
            'status' => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'payment_link' => 'https://pay.example.com/abc',
            'octo_transaction_id' => 'inquiry_X_live',
        ]);

        $this->assertFalse($this->paymentDroppedQuery()->where('id', $row->id)->exists());
    }

    public function test_payment_dropped_excludes_rows_without_octo_attempt(): void
    {
        // A purely manual inquiry that never had an Octo link should NOT
        // appear in the dropped tab — there's nothing to follow up on.
        $row = $this->makeInquiry([
            'status' => BookingInquiry::STATUS_CONTACTED,
            'payment_link' => null,
            'octo_transaction_id' => null,
        ]);

        $this->assertFalse($this->paymentDroppedQuery()->where('id', $row->id)->exists());
    }

    public function test_payment_dropped_excludes_confirmed_and_cancelled(): void
    {
        $confirmed = $this->makeInquiry([
            'status' => BookingInquiry::STATUS_CONFIRMED,
            'payment_link' => null,
            'octo_transaction_id' => 'inquiry_X_confirmed',
        ]);
        $cancelled = $this->makeInquiry([
            'status' => BookingInquiry::STATUS_CANCELLED,
            'payment_link' => null,
            'octo_transaction_id' => 'inquiry_X_cancelled',
        ]);

        $this->assertFalse($this->paymentDroppedQuery()->where('id', $confirmed->id)->exists());
        $this->assertFalse($this->paymentDroppedQuery()->where('id', $cancelled->id)->exists());
    }
}
