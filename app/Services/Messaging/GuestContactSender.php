<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Channel orchestration: try WhatsApp first, fall back to email.
 *
 * Rules:
 * - If email override is set → email only, skip WA
 * - If valid phone exists → try WA first
 * - If WA fails → fall back to email
 * - If both fail → do NOT mark as sent
 */
class GuestContactSender
{
    public function __construct(
        private WhatsAppSender $whatsApp,
        private EmailSender $email,
    ) {}

    /**
     * Send a message to a guest via best available channel.
     *
     * @param array{
     *   phone: ?string,
     *   email: ?string,
     *   wa_message: string,
     *   email_subject: string,
     *   email_body: string,
     *   booking_id: int,
     *   booking_ref: ?string,
     *   notification_type: string,
     * } $params
     */
    public function send(array $params): SendResult
    {
        $override = config('services.gyg.email_override_to');
        $bookingId = $params['booking_id'];
        $ref = $params['booking_ref'] ?? 'unknown';
        $type = $params['notification_type'];

        // Override mode: email only
        if ($override) {
            Log::info('GuestContactSender: override active, email only', [
                'booking_id' => $bookingId,
                'ref'        => $ref,
                'type'       => $type,
                'override'   => $override,
            ]);

            $result = $this->email->send($override, $params['email_subject'], $params['email_body']);
            $this->logOutcome($params, 'email_override', null, $result);
            return $result;
        }

        // Normal mode: try WA first, then email
        $normalizedPhone = $this->whatsApp->normalizePhone($params['phone'] ?? null);
        $waAttempted = false;
        $waResult = null;

        if ($normalizedPhone) {
            $waAttempted = true;
            $waResult = $this->whatsApp->send($normalizedPhone, $params['wa_message']);

            if ($waResult->success) {
                $this->logOutcome($params, 'whatsapp', $waResult, null);
                return $waResult;
            }

            // WA failed — log and fall back
            Log::warning('GuestContactSender: WA failed, falling back to email', [
                'booking_id'       => $bookingId,
                'ref'              => $ref,
                'type'             => $type,
                'normalized_phone' => $normalizedPhone,
                'wa_error'         => $waResult->error,
                'wa_retryable'     => $waResult->retryable,
            ]);
        }

        // Email fallback (or primary if no phone)
        $emailTo = $params['email'] ?? null;
        if (empty($emailTo)) {
            $result = SendResult::fail('none', 'no email address available');
            $this->logOutcome($params, 'none', $waResult, $result);
            return $result;
        }

        $emailResult = $this->email->send($emailTo, $params['email_subject'], $params['email_body']);
        $this->logOutcome($params, $waAttempted ? 'email_fallback' : 'email_primary', $waResult, $emailResult);
        return $emailResult;
    }

    private function logOutcome(array $params, string $strategy, ?SendResult $waResult, ?SendResult $finalResult): void
    {
        $result = $finalResult ?? $waResult;

        Log::info('GuestContactSender: delivery outcome', [
            'booking_id'      => $params['booking_id'],
            'ref'             => $params['booking_ref'] ?? 'unknown',
            'type'            => $params['notification_type'],
            'strategy'        => $strategy,
            'wa_attempted'    => $waResult !== null,
            'wa_success'      => $waResult?->success ?? false,
            'wa_error'        => $waResult?->error,
            'final_channel'   => $result?->channel ?? 'none',
            'final_success'   => $result?->success ?? false,
        ]);
    }
}
