<?php

declare(strict_types=1);

/**
 * Curated, human-style WhatsApp openers for the post-tour feedback message.
 *
 * Selection rule (FeedbackMessageBuilder):
 *   - Random pick from the pool
 *   - Avoid the immediately-previous opener_index for the same guest
 *
 * Editing:
 *   - Adding/removing/changing lines is safe — config-only, no DB
 *   - Live edits on the VPS need `php artisan config:clear` afterwards
 *   - {name} will be substituted with the guest's first name; if missing
 *     the FeedbackMessageBuilder strips the leading "Hey/Hi {name}"
 *     greeting gracefully.
 *
 * Style guardrails:
 *   - Under ~25 words
 *   - Casual-professional, never salesy or robotic
 *   - No emojis other than 👋 / 😊 (occasional)
 *   - No links, no CTA, no "thank you for choosing us"
 *   - Sounds like a thoughtful operator, not a brand
 *   - Slight imperfection welcome — too polished reads as automation
 *
 * Tonal mix is deliberate. Categories below are for human curation —
 * the runtime picks globally at random. If we ever want to segment
 * (e.g. shorter for repeat guests, warmer for first-timers), the keys
 * are already labelled.
 *
 * Cut ratio applied: dropped 12 phrases that read survey-template-ish
 * ("how everything turned out", "got back home safely", repeated "Just
 * touching base"). 38 entries chosen for tonal diversity over count.
 */
return [
    // ── Caring ───────────────────────────────────────────────────────
    // Empathetic, "you've been on our mind" texture.
    'Hey {name} 👋 It has been on our mind — how did everything end up going for you?',
    'Hi {name} 😊 We have been thinking about your trip — would love to hear how it was.',
    'Hey {name}, hope the experience left you with something good to remember.',
    'Hi {name} 👋 We have been hoping your trip gave you a few good memories.',
    'Hey {name} 😊 Wanted to see how you have been since the tour wrapped up.',
    'Hi {name}, the team has been wondering how the trip went for you.',
    'Hey {name} 👋 Hope you have been doing well since we last saw you.',
    'Hi {name}, just thinking back to your tour and wanted to say hi.',

    // ── Casual ───────────────────────────────────────────────────────
    // Short, light, conversational. Sounds like a quick WA tap.
    'Hey {name}! So… how did the adventure treat you?',
    'Hi {name} 😊 So — how was it really?',
    'Hey {name}, how was the trip? Any stories worth telling?',
    'Hi {name} 👋 How did everything go in the end?',
    'Hey {name}! Quick one — how was the tour?',
    'Hi {name}, hope it was a good one — how did it go?',
    'Hey {name} 😊 So, was it everything you were hoping for?',
    'Hi {name}, how was the tour for you?',

    // ── Reflective ───────────────────────────────────────────────────
    // Quieter, post-trip space, "now that some time has passed".
    'Hi {name} 😊 Now that the tour is behind you, we would love to know how it felt.',
    'Hey {name} 👋 Now that the dust has settled — how was it all?',
    'Hi {name}, now that you have had time to settle back in, how does the trip feel looking back?',
    'Hey {name} 😊 Now that you are back to normal life, how was the tour for you?',
    'Hey {name}, now that some time has passed, what stayed with you most?',
    'Hi {name} 😊 Looking back on it, how did the tour treat you?',

    // ── Warm host ────────────────────────────────────────────────────
    // Operator personality. "Was great to have you" / "glad you joined us".
    'Hi {name} 👋 Was great to have you with us — would love to hear how the tour went.',
    'Hey {name} 😊 Was a pleasure showing you around — how did it all feel?',
    'Hi {name}, glad you joined us. How did the tour land for you?',
    'Hey {name} 👋 Hope you took a piece of Uzbekistan home with you.',
    'Hi {name} 😊 Hope the country gave you a warm welcome — would love your honest take.',
    'Hey {name}, was so good having you on the tour. How did it go for you?',
    'Hi {name} 👋 Hope your time with us was a memorable one.',
    'Hey {name} 😊 Hope our country left a good impression on you.',

    // ── Slightly playful ─────────────────────────────────────────────
    // Light, friendly, a little personality.
    'Hey {name} 😊 So — verdict on the tour?',
    'Hi {name} 👋 You survived us! How was it really?',
    'Hey {name}, back to normal life? How was the trip?',
    'Hi {name} 😊 So, give us the verdict — how was the tour?',

    // ── Warm-professional ───────────────────────────────────────────
    // Polished but never corporate. Safe defaults.
    'Hi {name} 😊 Hope the trip was a good one and you have some great memories to take with you.',
    'Hey {name} 👋 Hope you had a wonderful time exploring Uzbekistan with us.',
    'Hi {name}, hope your journey with us was a meaningful one.',
    'Hey {name} 😊 Hope the tour gave you a real taste of what we love about this country.',
];
