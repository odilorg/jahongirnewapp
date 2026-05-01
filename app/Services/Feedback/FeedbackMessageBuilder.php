<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Models\BookingInquiry;

/**
 * Builds the WhatsApp/email body for the post-tour feedback reminder.
 *
 * One curated opener (random pick, anti-repeat for repeat guests) + a
 * fixed CTA block with the unique feedback link. No AI, no third-party
 * calls, no PII leaving the server.
 *
 * Returns ['text' => string, 'opener_index' => int|null] so the caller
 * can persist which phrase was used.
 *
 *   ['text' => "Hey Blake 👋 Just checking in — hope you had a great time...
 *
 *               We'd really love your honest feedback when you have 30 seconds 🙏
 *               ⭐ https://jahongir-app.uz/feedback/abc...",
 *    'opener_index' => 0]
 */
class FeedbackMessageBuilder
{
    public function build(BookingInquiry $inquiry, string $feedbackUrl): array
    {
        $openers = (array) config('feedback_openers', []);

        if ($openers === []) {
            // Defensive: never let an empty config crash the cron. The
            // operator can re-curate later; one safe fallback is enough.
            return [
                'text'         => $this->fallbackMessage($inquiry, $feedbackUrl),
                'opener_index' => null,
            ];
        }

        $previousIndex = $this->previousOpenerIndexForGuest($inquiry);
        $index         = $this->pickIndex(count($openers), $previousIndex);
        $opener        = $this->personalise($openers[$index], $inquiry);

        $text = $opener . "\n\n"
              . "We'd really love your honest feedback when you have 30 seconds 🙏\n"
              . "⭐ {$feedbackUrl}";

        return [
            'text'         => $text,
            'opener_index' => $index,
        ];
    }

    /**
     * Replace {name} with the guest's first name. If first name is missing,
     * strip a leading "Hey {name}" / "Hi {name}" so we don't end up with
     * "Hey , Just checking in".
     */
    private function personalise(string $template, BookingInquiry $inquiry): string
    {
        $first = trim((string) strtok((string) $inquiry->customer_name, ' '));

        if ($first !== '') {
            return str_replace('{name}', $first, $template);
        }

        // No first name → drop "Hey {name}" / "Hi {name}" + trailing punctuation.
        $stripped = preg_replace('/^(Hey|Hi)\s+\{name\}[,!\s]*/u', '', $template) ?? $template;

        // Capitalise whatever now starts the sentence.
        return ucfirst(ltrim($stripped));
    }

    private function pickIndex(int $count, ?int $avoid): int
    {
        if ($count <= 1) {
            return 0;
        }

        // Try up to a few times to avoid the previously-used index. With a
        // 49-entry pool the very first random pick virtually always wins;
        // the loop is just belt-and-braces.
        for ($i = 0; $i < 4; $i++) {
            $candidate = random_int(0, $count - 1);
            if ($avoid === null || $candidate !== $avoid) {
                return $candidate;
            }
        }

        return ($avoid + 1) % $count;
    }

    /**
     * If this guest has had a previous feedback row, what opener_index was
     * used? Used to avoid repeating it.
     */
    private function previousOpenerIndexForGuest(BookingInquiry $inquiry): ?int
    {
        $email = (string) $inquiry->customer_email;
        if ($email === '') {
            return null;
        }

        // Anti-repeat is a "nice to have" — if the lookup ever fails (DB
        // hiccup, partial schema), the cron must still run. Treat any
        // exception as "no previous index" and pick freely.
        try {
            return \App\Models\TourFeedback::query()
                ->whereNotNull('opener_index')
                ->whereHas('inquiry', fn ($q) => $q->where('customer_email', $email))
                ->orderByDesc('id')
                ->value('opener_index');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('FeedbackMessageBuilder: prev-index lookup failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fallbackMessage(BookingInquiry $inquiry, string $feedbackUrl): string
    {
        $first = trim((string) strtok((string) $inquiry->customer_name, ' '));
        $hi    = $first !== '' ? "Hi {$first} 👋" : 'Hi 👋';

        return $hi . " Hope you had a great trip with us.\n\n"
             . "We'd really love your honest feedback when you have 30 seconds 🙏\n"
             . "⭐ {$feedbackUrl}";
    }
}
