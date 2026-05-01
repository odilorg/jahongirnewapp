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
 *
 * Distribution (deliberately mixed to reduce repeat-guest pattern fatigue):
 *   - "Just checking in / Just wanted to check": ~12
 *   - "Hope you / your / the": ~22
 *   - "Quick note / Wanted to see / Wondering": ~10
 *   - Other natural openings: ~6
 */
return [
    // Just checking in / Just wanted to check (12)
    'Hey {name} 👋 Just checking in — hope you had a great time and got back home safely.',
    'Hi {name} 😊 Just wanted to check how everything went on the tour.',
    'Hey {name}! Just checking in to see how the tour went — hope it was a good one.',
    'Hi {name}, just touching base to see how everything went on your trip.',
    'Hey {name} 👋 Just wanted to drop a quick note and see how it all went.',
    'Hi {name}, just checking in — hope the experience was a memorable one.',
    'Hey {name}, just a quick check-in to ask how the trip went for you.',
    'Hi {name} 😊 Just wanted to see how the tour was and whether you got home alright.',
    'Hey {name} 👋 Just checking in — wanted to make sure everything went smoothly.',
    'Hi {name}, just touching base after the tour — would love to hear how it was.',
    'Hey {name}! Just checking how it all went — was thinking of you and your trip today.',
    'Hi {name}, just a small check-in to ask how things turned out on your tour.',

    // Wanted to see / Wondering (6)
    'Hey {name} 👋 Wanted to see how the tour went and whether everything went the way it should.',
    'Hi {name}, wondering how everything turned out — hope it was a good experience.',
    'Hey {name}, wanted to ask how the trip went now that you have had time to settle back in.',
    'Hi {name} 😊 Wanted to check in and see if you got back comfortably and enjoyed the time.',
    'Hey {name}, wondering how the tour played out — would love to hear from you.',
    'Hi {name}, wanted to drop a note and see how the journey was for you.',

    // Quick note / quick word (5)
    'Hey {name} 👋 A quick note from us — hope the tour was a good one and you got home safely.',
    'Hi {name}, a quick word to ask how the trip went and whether everything ran smoothly.',
    'Hey {name} 😊 A small message to check in after the tour — hope you had a memorable time.',
    'Hi {name}, quick note — wanted to see how it all went before you got too busy.',
    'Hey {name}, quick check after the trip — hope you have made it home in one piece.',

    // Hope you / Hope the / Hope your (22)
    'Hi {name} 😊 Hope your journey went smoothly and you enjoyed the experience.',
    'Hi {name} 👋 Hope you had a wonderful time exploring Uzbekistan with us.',
    'Hey {name} 😊 Hope your trip was everything you hoped for.',
    'Hey {name} 👋 Hope you got back comfortably and that the journey was a good one.',
    'Hi {name}, hope you enjoyed your time on the tour and made it home safely.',
    'Hi {name} 😊 Hope everything went well and you have some good memories to take home.',
    'Hey {name}, hope your tour was relaxing and you had a chance to enjoy yourself.',
    'Hi {name} 👋 Hope the trip lived up to expectations and you got home alright.',
    'Hi {name} 😊 Hope your time with us was enjoyable from start to finish.',
    'Hey {name}, hope you had a smooth journey and a great experience overall.',
    'Hi {name} 👋 Hope you enjoyed the tour and got back safe.',
    'Hey {name}, hope the trip went well and you got to see what you came for.',
    'Hi {name} 😊 Hope your adventure was a good one and you made it home in one piece.',
    'Hey {name} 👋 Hope you had a wonderful time and a safe trip back.',
    'Hey {name}, hope your journey was comfortable and the experience was worth it.',
    'Hi {name} 😊 Hope the tour gave you a real taste of Uzbekistan and you got home well.',
    'Hi {name}, hope your trip went smoothly and you have some stories to take home.',
    'Hi {name} 😊 Hope the trip was a good one and you enjoyed every part of it.',
    'Hey {name} 👋 Hope you got back home safely and had a great time along the way.',
    'Hi {name} 😊 Hope your travels with us were enjoyable and the journey home was smooth.',
    'Hey {name} 👋 Hope the tour was memorable and you made it back safely.',
    'Hi {name} 😊 Hope you got to enjoy the trip and that the trip back was uneventful.',

    // Other natural openings (5)
    'Hey {name}, how was the tour? Would love a quick word from you when you have a moment.',
    'Hi {name} 😊 So — how was the trip? Hoping it was a good one for you.',
    'Hey {name} 👋 Now that you are back — how did everything go for you on the tour?',
    'Hi {name}, thinking back to your tour and wondering how it all went for you in the end.',
    'Hey {name}, was great to have you with us — would love to hear how the trip went.',
];
