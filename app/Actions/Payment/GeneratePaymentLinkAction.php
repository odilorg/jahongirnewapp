<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Models\BookingInquiry;
use App\Models\OctoPaymentAttempt;
use App\Services\OctoPaymentService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generate (or regenerate) an Octo payment link for a booking inquiry.
 *
 * Supports split payment: operator can charge part of the quote online and
 * collect the remainder in cash at pickup. The online portion is what gets
 * sent to Octo; the cash portion is recorded on the inquiry for operational
 * visibility (it is NOT a received payment — just an expectation).
 *
 * Phase 3: supports regeneration. When an active attempt already exists it
 * is superseded inside the same DB transaction that creates the new one,
 * ensuring the attempt table is always consistent (≤1 active per inquiry).
 *
 * Extracted from BookingInquiryResource::waGenerateAndSend() closure to
 * comply with the repo rule "no business logic inside Filament closures"
 * (CLAUDE.md + PRINCIPLES.md §11).
 */
class GeneratePaymentLinkAction
{
    public function __construct(
        private readonly OctoPaymentService $octo,
    ) {
    }

    /**
     * Execute — validate, call Octo, persist, and build the WhatsApp payload.
     *
     * Returns everything the caller needs to open WhatsApp: template key,
     * placeholder extras, and the audit note label. Keeps the Filament
     * closure thin (≤10 LOC per repo rule 11).
     *
     * Phase 3 supersede logic (external-call-first):
     *  1. Read the current active attempt (if any) before calling Octo.
     *  2. Call Octo — get the new URL + transaction_id.
     *  3. Inside a single DB::transaction with lockForUpdate on the inquiry:
     *     a. Supersede the prior active attempt (status → superseded).
     *     b. Create the new active attempt.
     *     c. Update the inquiry pointer (payment_link, octo_transaction_id).
     *  If the DB transaction fails after Octo has already accepted the
     *  request, the new Octo transaction is orphaned (no attempt row, no
     *  inquiry pointer). This is logged at error level so ops can clean up,
     *  but we never retry the Octo call — the money link is not yet sent to
     *  the customer so no payment can arrive on an orphan transaction.
     *
     * @param  BookingInquiry  $inquiry
     * @param  float  $total   Total tour price in USD (source of truth, saved to price_quoted)
     * @param  float  $online  Amount to charge via Octo in USD
     * @return array{
     *     url: string,
     *     transaction_id: string,
     *     uzs_amount: int,
     *     split: string,
     *     template_key: string,
     *     template_extras: array<string, string>,
     *     audit_label: string,
     * }
     *
     * @throws InvalidArgumentException  On validation failure
     * @throws \Throwable                On Octo API or DB failure (surfaced as-is)
     */
    public function execute(BookingInquiry $inquiry, float $total, float $online): array
    {
        $this->validate($total, $online);

        // Read prior active attempt BEFORE calling Octo so we know what to
        // supersede inside the transaction. Using the fresh model value — the
        // Filament action already re-fetches $record from DB, so this is safe.
        $priorAttemptId = $inquiry->activePaymentAttempt()->value('id');

        $octoResult = $this->octo->createPaymentLinkForInquiry($inquiry, $online);

        // Compare as floats with epsilon — decimal casts round to 2dp so
        // direct equality can miss by $0.001 on weird inputs.
        $isFullOnline = abs($online - $total) < 0.01;
        $split        = $isFullOnline
            ? BookingInquiry::PAYMENT_SPLIT_FULL
            : BookingInquiry::PAYMENT_SPLIT_PARTIAL;
        $cash         = round($total - $online, 2);

        DB::transaction(function () use ($inquiry, $total, $online, $cash, $split, $octoResult, $priorAttemptId) {
            // Re-lock the inquiry row to serialise concurrent regenerations.
            // lockForUpdate prevents two operators from both reading
            // "no active attempt" and both creating one in parallel.
            BookingInquiry::lockForUpdate()->find($inquiry->id);

            // Supersede the prior active attempt (if any). Re-query inside
            // the lock to catch any attempt created after our pre-Octo read.
            OctoPaymentAttempt::where('inquiry_id', $inquiry->id)
                ->where('status', OctoPaymentAttempt::STATUS_ACTIVE)
                ->update(['status' => OctoPaymentAttempt::STATUS_SUPERSEDED]);

            $inquiry->update([
                // price_quoted IS the "total tour price" field in the modal —
                // operators rely on this to correct quotes at the payment
                // step. Kept as source-of-truth.
                'price_quoted'         => $total,
                'amount_online_usd'    => $online,
                'amount_cash_usd'      => $cash,
                'payment_split'        => $split,
                'currency'             => 'USD',
                'payment_method'       => BookingInquiry::PAYMENT_ONLINE,
                'payment_link'         => $octoResult['url'],
                'payment_link_sent_at' => now(),
                'octo_transaction_id'  => $octoResult['transaction_id'],
                'status'               => BookingInquiry::STATUS_AWAITING_PAYMENT,
            ]);

            // Exchange rate is derived from the UZS amount the service
            // actually sent to Octo (uzs_amount / online), avoiding a service
            // signature change. Accurate to 4dp — fine for audit.
            OctoPaymentAttempt::create([
                'inquiry_id'              => $inquiry->id,
                'transaction_id'          => $octoResult['transaction_id'],
                'amount_online_usd'       => $online,
                'price_quoted_at_attempt' => $total,
                'exchange_rate_used'      => $online > 0
                    ? round($octoResult['uzs_amount'] / $online, 4)
                    : null,
                'uzs_amount'              => $octoResult['uzs_amount'],
                'status'                  => OctoPaymentAttempt::STATUS_ACTIVE,
            ]);
        });

        $totalFmt  = '$' . number_format($total, 2);
        $onlineFmt = '$' . number_format($online, 2);
        $cashFmt   = '$' . number_format($cash, 2);
        $isRegen   = $priorAttemptId !== null;

        return [
            'url'             => $octoResult['url'],
            'transaction_id'  => $octoResult['transaction_id'],
            'uzs_amount'      => $octoResult['uzs_amount'],
            'split'           => $split,
            'is_regeneration' => $isRegen,
            'template_key'    => $isFullOnline ? 'wa_generate_and_send' : 'wa_generate_and_send_partial',
            'template_extras' => $isFullOnline
                ? ['price' => $totalFmt, 'link' => $octoResult['url']]
                : [
                    'total'  => $totalFmt,
                    'online' => $onlineFmt,
                    'cash'   => $cashFmt,
                    'link'   => $octoResult['url'],
                ],
            'audit_label'     => $isRegen
                ? ($isFullOnline
                    ? "Payment link regenerated & sent ({$totalFmt}) — prior link superseded"
                    : "Payment link regenerated & sent ({$onlineFmt} online + {$cashFmt} cash) — prior link superseded")
                : ($isFullOnline
                    ? "Payment link generated & sent ({$totalFmt})"
                    : "Payment link generated & sent ({$onlineFmt} online + {$cashFmt} cash)"),
        ];
    }

    private function validate(float $total, float $online): void
    {
        if ($total <= 0) {
            throw new InvalidArgumentException('Total tour price must be greater than 0.');
        }

        if ($online < BookingInquiry::MIN_ONLINE_USD) {
            throw new InvalidArgumentException(sprintf(
                'Online payment amount must be at least $%d.',
                BookingInquiry::MIN_ONLINE_USD,
            ));
        }

        if ($online > $total + 0.01) {
            throw new InvalidArgumentException(
                'Online payment amount cannot exceed total tour price.'
            );
        }
    }
}
