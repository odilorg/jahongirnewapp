<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Models\BookingInquiry;
use App\Services\OctoPaymentService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generate an Octo payment link for a booking inquiry.
 *
 * Supports split payment: operator can charge part of the quote online and
 * collect the remainder in cash at pickup. The online portion is what gets
 * sent to Octo; the cash portion is recorded on the inquiry for operational
 * visibility (it is NOT a received payment — just an expectation).
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
     * Race guard: refuses if the inquiry already has a payment_link. The
     * Filament visibility rule already hides the action in that case; this
     * is defense-in-depth for two operators clicking simultaneously, which
     * would otherwise orphan the first Octo transaction.
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
     * @throws RuntimeException          On race (link already exists)
     * @throws \Throwable                On Octo API failure (surfaced as-is)
     */
    public function execute(BookingInquiry $inquiry, float $total, float $online): array
    {
        $this->validate($total, $online);

        if (filled($inquiry->payment_link)) {
            throw new RuntimeException(
                'Payment link already exists for this inquiry — use "Resend existing link" instead.'
            );
        }

        $octoResult = $this->octo->createPaymentLinkForInquiry($inquiry, $online);

        // Compare as floats with epsilon — decimal casts round to 2dp so
        // direct equality can miss by $0.001 on weird inputs.
        $isFullOnline = abs($online - $total) < 0.01;
        $split        = $isFullOnline
            ? BookingInquiry::PAYMENT_SPLIT_FULL
            : BookingInquiry::PAYMENT_SPLIT_PARTIAL;
        $cash         = round($total - $online, 2);

        DB::transaction(function () use ($inquiry, $total, $online, $cash, $split, $octoResult) {
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
        });

        $totalFmt  = '$' . number_format($total, 2);
        $onlineFmt = '$' . number_format($online, 2);
        $cashFmt   = '$' . number_format($cash, 2);

        return [
            'url'             => $octoResult['url'],
            'transaction_id'  => $octoResult['transaction_id'],
            'uzs_amount'      => $octoResult['uzs_amount'],
            'split'           => $split,
            'template_key'    => $isFullOnline ? 'wa_generate_and_send' : 'wa_generate_and_send_partial',
            'template_extras' => $isFullOnline
                ? ['price' => $totalFmt, 'link' => $octoResult['url']]
                : [
                    'total'  => $totalFmt,
                    'online' => $onlineFmt,
                    'cash'   => $cashFmt,
                    'link'   => $octoResult['url'],
                ],
            'audit_label'     => $isFullOnline
                ? "Payment link generated & sent ({$totalFmt})"
                : "Payment link generated & sent ({$onlineFmt} online + {$cashFmt} cash)",
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
