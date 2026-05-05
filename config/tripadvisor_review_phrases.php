<?php

declare(strict_types=1);

/**
 * Manual public TripAdvisor review-request phrase pool.
 *
 * Selection rule (PublicReviewRequestMessageFactory):
 *   - Random pick from the pool
 *   - Avoid the last opener_index used for the same guest if possible
 *
 * Style guardrails (matches feedback_openers.php):
 *   - Warm, short, never salesy
 *   - {name} substitutes the guest's first name
 *   - No links inside the opener — the TripAdvisor URL is appended
 *     by the message factory in a fixed CTA block
 *
 * Editing:
 *   - Live edits on VPS need `sudo -u www-data php artisan config:clear`
 *   - Adding/removing/changing lines is safe — config-only
 */
return [
    'Hi {name} 👋 It was a pleasure having you with us. If you enjoyed the tour, a quick TripAdvisor review would mean a lot to us and really helps future travelers find us 🙏',
    'Hi {name} 😊 Thank you again for joining us. If you have a minute, we would be very grateful for a short TripAdvisor review — even a few words helps a lot 🙏',
    'Hey {name} 👋 Hope you took home some great memories from Uzbekistan. If you enjoyed the experience, would you mind leaving us a quick TripAdvisor review?',
    'Hi {name} 😊 We are really glad you joined us. A short TripAdvisor review would help our small local team a lot if you enjoyed the trip 🙏',
    'Hi {name} 👋 Just wanted to say thank you again for traveling with us. If everything went well, your TripAdvisor review would really support our team.',
];
