<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Models\TourFeedback;
use App\Services\OpsBotClient;

/**
 * Telegram alert sent the moment a guest submits a feedback row whose
 * worst rating is ≤ 3 stars.
 *
 * Pure presentation: build the message, hand it to OpsBotClient. Never
 * throws — callers (FeedbackController) already wrap in try/catch.
 */
class LowRatingAlertNotifier
{
    public function __construct(
        private readonly OpsBotClient $opsBot,
    ) {}

    public function alert(TourFeedback $feedback): void
    {
        $feedback->loadMissing(['inquiry', 'driver', 'guide', 'accommodation']);

        $this->opsBot->send($this->buildMessage($feedback), html: true);
    }

    private function buildMessage(TourFeedback $feedback): string
    {
        $inquiry = $feedback->inquiry;
        $ref     = htmlspecialchars((string) $inquiry?->reference, ENT_QUOTES, 'UTF-8');
        $name    = htmlspecialchars((string) $inquiry?->customer_name, ENT_QUOTES, 'UTF-8');

        $lines = [];
        $lines[] = '⚠️ <b>Low feedback</b> — ' . $ref . ' · ' . $name;
        $lines[] = '';

        if ($feedback->driver_id) {
            $lines[] = $this->roleLine(
                emoji: '🚗',
                label: 'Driver',
                supplierName: $feedback->driver?->full_name,
                rating: $feedback->driver_rating,
                tags: $feedback->driver_issue_tags,
                tagDict: (array) config('feedback_issue_tags.driver', []),
            );
        }

        if ($feedback->guide_id) {
            $lines[] = $this->roleLine(
                emoji: '🧭',
                label: 'Guide',
                supplierName: $feedback->guide?->full_name,
                rating: $feedback->guide_rating,
                tags: $feedback->guide_issue_tags,
                tagDict: (array) config('feedback_issue_tags.guide', []),
            );
        }

        if ($feedback->accommodation_id) {
            $lines[] = $this->roleLine(
                emoji: '🏕',
                label: 'Accommodation',
                supplierName: $feedback->accommodation?->name,
                rating: $feedback->accommodation_rating,
                tags: $feedback->accommodation_issue_tags,
                tagDict: (array) config('feedback_issue_tags.accommodation', []),
            );
        }

        if ($feedback->overall_rating !== null) {
            $lines[] = '⭐ Overall: ' . str_repeat('★', (int) $feedback->overall_rating)
                . str_repeat('☆', 5 - (int) $feedback->overall_rating);
        }

        if (filled($feedback->comments)) {
            $comment = htmlspecialchars(
                mb_substr((string) $feedback->comments, 0, 800),
                ENT_QUOTES,
                'UTF-8',
            );
            $lines[] = '';
            $lines[] = '💬 <i>' . $comment . '</i>';
        }

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    private function roleLine(
        string $emoji,
        string $label,
        ?string $supplierName,
        ?int $rating,
        ?array $tags,
        array $tagDict,
    ): ?string {
        if ($rating === null) {
            return null;
        }

        $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
        $name  = $supplierName ? ' ' . htmlspecialchars($supplierName, ENT_QUOTES, 'UTF-8') : '';

        $line = $emoji . ' ' . $label . $name . ': ' . $stars;

        if ($rating <= 3 && filled($tags)) {
            $labels = array_map(
                static fn (string $key) => $tagDict[$key] ?? $key,
                (array) $tags,
            );
            $line .= '  [' . htmlspecialchars(implode(', ', $labels), ENT_QUOTES, 'UTF-8') . ']';
        }

        return $line;
    }
}
