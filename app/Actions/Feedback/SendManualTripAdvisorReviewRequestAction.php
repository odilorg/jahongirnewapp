<?php

declare(strict_types=1);

namespace App\Actions\Feedback;

use App\Models\BookingInquiry;
use App\Services\Feedback\PublicReviewRequestMessageFactory;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Send the public TripAdvisor review request manually from the
 * BookingInquiry view page (Phase 1.7.0 / 2026-05-05).
 *
 * Channel ladder (matches existing tour:send-public-review-reminders
 * legacy command):
 *   1. WhatsApp — uses normalised phone, fastest read rate
 *   2. Email   — himalaya CLI via the same template-send path the
 *                cron used; only attempted when phone fails or is missing
 *
 * Idempotency:
 *   - review_request_sent_at is stamped ONLY on successful send. A
 *     transport failure leaves the column NULL so the operator can
 *     retry. Caller (Filament action) decides whether to allow a
 *     resend when the column is already filled — the action itself
 *     never blocks; that's a UX decision.
 *
 * Why an explicit Action class:
 *   - CLAUDE.md hard line: no business logic past ~10 LOC inside
 *     Filament closures.
 *   - Same path is reachable from artisan, jobs, and tests.
 *   - Single source of truth for "what happens when an operator hits
 *     the TripAdvisor button" — message composition, send, stamp.
 */
class SendManualTripAdvisorReviewRequestAction
{
    public function __construct(
        private WhatsAppSender $whatsApp,
        private PublicReviewRequestMessageFactory $messageFactory,
    ) {}

    /**
     * @return array{
     *   sent:    bool,
     *   channel: ?string,         // 'whatsapp' | 'email' | null on fail
     *   reason:  ?string,         // human-readable error when sent=false
     *   text:    string,          // composed message (always returned for audit)
     *   opener_index: ?int,
     * }
     */
    public function execute(BookingInquiry $inquiry, ?int $operatorUserId = null): array
    {
        $built = $this->messageFactory->build($inquiry);
        $text  = $built['text'];

        $channel = null;
        $reason  = null;

        // 1. WhatsApp
        $phone = $this->whatsApp->normalizePhone((string) $inquiry->customer_phone);
        if ($phone !== null && $phone !== '') {
            try {
                $result = $this->whatsApp->send($phone, $text);
                if ($result->success) {
                    $channel = 'whatsapp';
                } else {
                    $reason = 'WhatsApp failed: ' . ($result->error ?? 'unknown');
                }
            } catch (\Throwable $e) {
                $reason = 'WhatsApp threw: ' . $e->getMessage();
            }
        }

        // 2. Email fallback (only if WA didn't succeed)
        if ($channel === null && filled($inquiry->customer_email)) {
            if ($this->sendEmail((string) $inquiry->customer_email, $text)) {
                $channel = 'email';
                $reason  = null;
            } else {
                $reason = ($reason ? $reason . ' · ' : '') . 'email send failed';
            }
        }

        if ($channel === null) {
            // No working channel. Don't stamp; operator can fix
            // contact info and retry.
            Log::warning('SendManualTripAdvisorReviewRequestAction: no channel succeeded', [
                'inquiry_id' => $inquiry->id,
                'reason'     => $reason,
                'operator'   => $operatorUserId,
            ]);
            return [
                'sent'         => false,
                'channel'      => null,
                'reason'       => $reason ?: 'No phone or email available',
                'text'         => $text,
                'opener_index' => $built['opener_index'],
            ];
        }

        // Stamp ONLY on success. forceFill avoids the silent $fillable
        // miss noted in feedback_no_mass_assign_for_system_state.
        DB::transaction(function () use ($inquiry) {
            $inquiry->forceFill(['review_request_sent_at' => now()])->save();
        });

        Log::info('SendManualTripAdvisorReviewRequestAction: sent', [
            'inquiry_id'   => $inquiry->id,
            'reference'    => $inquiry->reference,
            'channel'      => $channel,
            'opener_index' => $built['opener_index'],
            'operator'     => $operatorUserId,
        ]);

        return [
            'sent'         => true,
            'channel'      => $channel,
            'reason'       => null,
            'text'         => $text,
            'opener_index' => $built['opener_index'],
        ];
    }

    /**
     * Email send via himalaya — same shape as the legacy cron's
     * sendEmail(). Lifted as-is so transport behaviour is byte-identical
     * for the operator-driven path.
     */
    private function sendEmail(string $email, string $body): bool
    {
        $subject = 'A quick favour — would you mind a TripAdvisor review?';
        $mml     = "From: odilorg@gmail.com\nTo: {$email}\nSubject: {$subject}\n\n{$body}";
        $tmpFile = tempnam(sys_get_temp_dir(), 'tripadv_') . '.eml';
        file_put_contents($tmpFile, $mml);

        $out = [];
        exec('himalaya template send < ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
        @unlink($tmpFile);

        if ($code === 0) {
            return true;
        }

        Log::warning('SendManualTripAdvisorReviewRequestAction: email failed', [
            'email'  => $email,
            'output' => implode("\n", $out),
        ]);
        return false;
    }
}
