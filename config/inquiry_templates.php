<?php

declare(strict_types=1);

/**
 * WhatsApp message templates for booking inquiry follow-up.
 *
 * Plain-text strings with {placeholder} tokens rendered by
 * App\Services\InquiryTemplateRenderer.
 *
 * Available placeholders (always safe):
 *   {name}   — customer first name
 *   {tour}   — tour_name_snapshot (as submitted from the website)
 *   {pax}    — e.g. "2 adults" or "2 adults, 1 child"
 *   {date}   — formatted travel date, or "your selected date" if not set
 *
 * Additional placeholders passed by specific actions:
 *   {price}  — total group price entered by the operator (wa_offer_payment)
 *   {link}   — payment link entered by the operator (wa_payment_link)
 *
 * Copy can be edited directly on the VPS (SSH + nano) followed by
 * `php artisan config:clear`. No code deploy required for wording tweaks.
 */
return [

    'wa_initial' => <<<TXT
Hi {name} 😊
Thank you for your request for {tour}.

The tour is available for your selected date 👍

Just to confirm:
– number of people: {pax}
– travel date: {date}
– your hotel location

Once confirmed, I will send you the final details and payment options.
TXT,

    'wa_offer_payment' => <<<TXT
Perfect 👍 thank you for confirming.

Here are your tour details:
• Tour: {tour}
• Date: {date}
• People: {pax}

The total price for your group is {price}.

You can confirm your booking with one of the following options:
• 💵 Cash in our office
• 💳 Card payment in the office
• 🔐 Secure online payment link

Please let me know which option works best for you 🙂
TXT,

    'wa_payment_link' => <<<TXT
Great 👍

Here is your secure payment link:
{link}

Once payment is completed, your booking will be fully confirmed.

Please let me know after payment 🙂
TXT,

    'wa_generate_and_send' => <<<TXT
Perfect 👍

Here is your secure payment link for {price}:
{link}

Once payment is completed, your booking will be fully confirmed.

Please let me know after payment 🙂
TXT,

];
