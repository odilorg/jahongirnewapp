<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Models\BookingInquiry;
use App\Models\TourFeedback;
use Illuminate\Support\Facades\Log;

/**
 * Builds the WhatsApp/email body for the MANUAL TripAdvisor review
 * request triggered by an operator from the Filament booking page.
 *
 * Composition mirrors FeedbackMessageBuilder shape so the two systems
 * stay visually consistent: random opener (with anti-repeat against
 * the same guest's last opener_index) + fixed CTA block + signature.
 *
 * Returns ['text' => string, 'opener_index' => int|null] so the caller
 * can persist which phrase was used (anti-repeat fuel for next send).
 */
class PublicReviewRequestMessageFactory
{
    private const TRIPADVISOR_URL = 'https://www.tripadvisor.com/UserReviewEdit-g298068-d17464942-Jahongir_Travel-Samarkand_Samarqand_Province.html';

    /**
     * @return array{text: string, opener_index: int|null}
     */
    public function build(BookingInquiry $inquiry): array
    {
        $phrases = (array) config('tripadvisor_review_phrases', []);

        if ($phrases === []) {
            // Defensive: never let an empty config crash the operator
            // action. One safe fallback is enough.
            return [
                'text'         => $this->fallback($inquiry),
                'opener_index' => null,
            ];
        }

        $previousIndex = $this->previousOpenerIndexForGuest($inquiry);
        $index         = $this->pickIndex(count($phrases), $previousIndex);
        $opener        = $this->personalise($phrases[$index], $inquiry);

        $text = $opener . "\n\n"
              . '🌟 TripAdvisor: ' . self::TRIPADVISOR_URL . "\n\n"
              . "Thank you!\n— Jahongir Travel";

        return [
            'text'         => $text,
            'opener_index' => $index,
        ];
    }

    private function personalise(string $template, BookingInquiry $inquiry): string
    {
        $first = trim((string) strtok((string) $inquiry->customer_name, ' '));

        if ($first !== '') {
            return str_replace('{name}', $first, $template);
        }

        // No first name → strip leading "Hi/Hey {name} ..." greeting so
        // we don't end up with "Hi , It was a pleasure..."
        $stripped = preg_replace('/^(Hey|Hi)\s+\{name\}[,!\s]*/u', '', $template) ?? $template;
        return ucfirst(ltrim($stripped));
    }

    private function pickIndex(int $count, ?int $avoid): int
    {
        if ($count <= 1) {
            return 0;
        }

        for ($i = 0; $i < 4; $i++) {
            $candidate = random_int(0, $count - 1);
            if ($avoid === null || $candidate !== $avoid) {
                return $candidate;
            }
        }

        return ($avoid + 1) % $count;
    }

    /**
     * Reuses tour_feedbacks.opener_index — same column as the Day-1
     * internal feedback message. Two different message systems sharing
     * the column is intentional: anti-repeat is loose ("don't use the
     * exact phrase the guest last saw"), not strict, so the small
     * cross-system overlap is fine.
     */
    private function previousOpenerIndexForGuest(BookingInquiry $inquiry): ?int
    {
        $email = (string) $inquiry->customer_email;
        if ($email === '') {
            return null;
        }

        try {
            return TourFeedback::query()
                ->whereNotNull('opener_index')
                ->whereHas('inquiry', fn ($q) => $q->where('customer_email', $email))
                ->orderByDesc('id')
                ->value('opener_index');
        } catch (\Throwable $e) {
            Log::warning('PublicReviewRequestMessageFactory: prev-index lookup failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fallback(BookingInquiry $inquiry): string
    {
        $first = trim((string) strtok((string) $inquiry->customer_name, ' '));
        $hi    = $first !== '' ? "Hi {$first} 👋" : 'Hi 👋';

        return $hi . " Hope you had a wonderful time with us.\n\n"
             . 'If you enjoyed the trip, a short TripAdvisor review would mean a lot 🙏' . "\n\n"
             . '🌟 TripAdvisor: ' . self::TRIPADVISOR_URL . "\n\n"
             . "Thank you!\n— Jahongir Travel";
    }
}
