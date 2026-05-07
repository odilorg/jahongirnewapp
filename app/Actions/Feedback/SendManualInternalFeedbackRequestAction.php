<?php

declare(strict_types=1);

namespace App\Actions\Feedback;

use App\Models\BookingInquiry;
use App\Models\TourFeedback;
use App\Services\Feedback\FeedbackMessageBuilder;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Send the Day-1 internal feedback request manually from the
 * BookingInquiry view page (2026-05-07).
 *
 * Symmetric with SendManualTripAdvisorReviewRequestAction. The
 * automatic cron (tour:send-review-requests) was kept on schedule
 * by Phase 1.7.0 — this Action is the operator-driven replacement
 * once that cron is also disabled. Operators (not the system) decide
 * which guests to ping, mirroring the public-review decision: they
 * read the tour-day vibes far better than a date filter does, so
 * even an internal feedback message is best sent by hand.
 *
 * Channel ladder (matches the legacy CLI tour:send-review-requests
 * exactly so transport behaviour is byte-identical):
 *   1. WhatsApp — uses normalised phone, fastest read rate
 *   2. Email   — himalaya CLI fallback, same template-send path the
 *                cron used; only attempted when phone fails or is
 *                missing
 *
 * Doctrine:
 *   - A TourFeedback row (with token + supplier snapshot) is
 *     pre-created BEFORE the send so a partial send-then-crash
 *     doesn't leave the operator wondering whether the link went
 *     out. If both channels fail the orphan is deleted so a retry
 *     generates a fresh token + opener.
 *   - feedback_request_sent_at is stamped ONLY on successful send.
 *     Caller (Filament action) decides whether to allow a resend
 *     when the column is already filled — this Action never blocks.
 *   - forceFill avoids the silent $fillable miss noted in
 *     feedback_no_mass_assign_for_system_state.
 */
class SendManualInternalFeedbackRequestAction
{
    public function __construct(
        private WhatsAppSender $whatsApp,
        private FeedbackMessageBuilder $messageBuilder,
    ) {}

    /**
     * @return array{
     *   sent:    bool,
     *   channel: ?string,         // 'whatsapp' | 'email' | null on fail
     *   reason:  ?string,         // human-readable error when sent=false
     *   text:    string,          // composed message (always returned for audit)
     *   opener_index: ?int,
     *   feedback_id: ?int,        // TourFeedback row id (null on full failure)
     * }
     */
    public function execute(BookingInquiry $inquiry, ?int $operatorUserId = null): array
    {
        $feedback = TourFeedback::create([
            'inquiry_id'       => $inquiry->id,
            'driver_id'        => $inquiry->driver_id,
            'guide_id'         => $inquiry->guide_id,
            'accommodation_id' => $inquiry->stays->first()?->accommodation_id,
            'token'            => TourFeedback::generateToken(),
            'source'           => 'whatsapp',
        ]);

        $url   = url('/feedback/' . $feedback->token);
        $built = $this->messageBuilder->build($inquiry, $url);
        $text  = $built['text'];

        $channel = null;
        $reason  = null;

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

        if ($channel === null && filled($inquiry->customer_email)) {
            if ($this->sendEmail((string) $inquiry->customer_email, $text)) {
                $channel = 'email';
                $reason  = null;
            } else {
                $reason = ($reason ? $reason . ' · ' : '') . 'email send failed';
            }
        }

        if ($channel === null) {
            // No working channel — clean up the orphan so the operator's
            // retry generates a fresh token + opener.
            $feedback->delete();
            Log::warning('SendManualInternalFeedbackRequestAction: no channel succeeded', [
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
                'feedback_id'  => null,
            ];
        }

        DB::transaction(function () use ($inquiry, $feedback, $channel, $built) {
            $inquiry->forceFill(['feedback_request_sent_at' => now()])->save();
            $feedback->forceFill([
                'source'       => $channel,
                'opener_index' => $built['opener_index'],
            ])->save();
        });

        Log::info('SendManualInternalFeedbackRequestAction: sent', [
            'inquiry_id'   => $inquiry->id,
            'reference'    => $inquiry->reference,
            'feedback_id'  => $feedback->id,
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
            'feedback_id'  => $feedback->id,
        ];
    }

    /**
     * Email send via himalaya — same shape as the legacy cron's
     * sendEmail(). Lifted as-is so transport behaviour is byte-identical
     * for the operator-driven path.
     */
    private function sendEmail(string $email, string $body): bool
    {
        $subject = 'How was your trip? · Jahongir Travel';
        $mml     = "From: odilorg@gmail.com\nTo: {$email}\nSubject: {$subject}\n\n{$body}";
        $tmpFile = tempnam(sys_get_temp_dir(), 'fb_') . '.eml';
        file_put_contents($tmpFile, $mml);

        $out = [];
        exec('himalaya template send < ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
        @unlink($tmpFile);

        if ($code === 0) {
            return true;
        }

        Log::warning('SendManualInternalFeedbackRequestAction: email failed', [
            'email'  => $email,
            'output' => implode("\n", $out),
        ]);

        return false;
    }
}
