<?php

declare(strict_types=1);

namespace App\Actions\Feedback;

use App\Models\TourFeedback;
use App\Services\Feedback\LowRatingAlertNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persist a guest's feedback submission and fire the low-rating Telegram
 * alert when applicable. Idempotent: a row that's already submitted is
 * returned unchanged.
 *
 * Returns true on persisted-or-already-submitted; false on hard failure
 * so the controller can render the error view.
 */
final class SubmitFeedbackAction
{
    public function __construct(
        private readonly LowRatingAlertNotifier $lowRatingAlert,
    ) {}

    public function execute(TourFeedback $feedback, array $data, string $ip): bool
    {
        // Idempotency: if it's already filled, do nothing — controller will
        // still show the thank-you page.
        if ($feedback->submitted_at !== null) {
            return true;
        }

        try {
            DB::transaction(function () use ($feedback, $data, $ip) {
                $feedback->forceFill(array_merge($data, [
                    'submitted_at' => now(),
                    'ip_address'   => $ip,
                ]))->save();
            });
        } catch (Throwable $e) {
            Log::error('SubmitFeedbackAction: persist failed', [
                'feedback_id' => $feedback->id,
                'error'       => $e->getMessage(),
            ]);

            return false;
        }

        if ($feedback->refresh()->isLowRated()) {
            try {
                $this->lowRatingAlert->alert($feedback);
            } catch (Throwable $e) {
                // Alert failure must NEVER affect the guest response.
                Log::warning('SubmitFeedbackAction: low-rating alert failed', [
                    'feedback_id' => $feedback->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return true;
    }
}
