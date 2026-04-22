<?php

declare(strict_types=1);

namespace App\Support\BookingBot;

/**
 * Static help text for the @j_booking_hotel_bot /help command.
 *
 * Plain text (no Markdown): operators copy/paste rows into other
 * chats, and Telegram's * and ` metacharacters leak formatting when
 * pasted. Emoji are kept — they render as graphemes, not markup.
 *
 * Every create/modify example includes the property (hotel|premium).
 * Ambiguous examples are deliberately excluded: /help is behavior
 * training — a bad example trains bad prompts.
 */
final class HelpContent
{
    public static function render(): string
    {
        return <<<TXT
🏨 Booking Bot — Help

Important:
• Always specify the property: jahongir hotel or premium
• Dates: today, tomorrow, aug 25, aug 25-27, 25/08
• Phone: tel +998901112233
• Email (optional): email guest@mail.com
• You can type naturally — the bot understands variations

Properties:
• Jahongir Hotel — say hotel, jahongir hotel, jahongir_hotel
• Jahongir Premium — say premium, jahongir premium, jahongir_premium

━━━━━━━━━━━━━━━━━━━━

📅 View bookings
• bookings today
• bookings tomorrow
• arrivals today
• departures today
• current guests
• new bookings
• bookings aug 25-27

🔎 Show one booking
• booking 12345
• show 12345

🔍 Search guest
• find Walker
• search +998901112233

✅ Check availability
• check avail aug 25-27
• available rooms tomorrow

🛏 Create booking (property required)
• book room 12 at jahongir hotel under John Walker aug 25-27 tel +998901112233 email a@b.com at 50 usd/night
• book room 14 at premium under Jane Doe aug 26-28 tel +998901112233 at 60 usd/night

👥 Group booking (same property, multiple rooms)
• book rooms 12, 14 at jahongir hotel under ACME Tour aug 25-27 tel +998901112233 at 50 usd/night

✏️ Modify booking
• move 12345 to aug 26-28
• change 12345 dates to aug 26-28

❌ Cancel booking
• cancel 12345

━━━━━━━━━━━━━━━━━━━━

Common mistakes:
❌ book room 12 under John ...
   (missing property — room may exist in both hotels)

✅ book room 12 at jahongir hotel under John ...

Tap « Back to Menu » or type menu to return.
TXT;
    }
}
