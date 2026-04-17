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

    /*
     * Driver dispatch template — sent via tg-direct (Odil's personal
     * Telegram) when an operator hits "Dispatch via Telegram" in the
     * admin. Uzbek by default since dispatch goes to local drivers.
     *
     * Placeholders (all from App\Services\DriverDispatchNotifier):
     *   {reference}       INQ-YYYY-NNNNNN
     *   {tour}            tour_name_snapshot
     *   {travel_date}     Y-m-d
     *   {pickup_time}     H:i or "—"
     *   {pickup_point}    free text or "—"
     *   {pax}             "2 adults" / "2 adults, 1 child"
     *   {customer_name}
     *   {customer_phone}
     *   {notes}           operational_notes or ""
     */
    /*
     * Driver dispatch — Uzbek. Matches the format Jahongir Travel ops
     * already uses when dispatching drivers manually. Placeholders are
     * filled by DriverDispatchNotifier::buildMessage().
     *
     * Fields without data render as "—" so the operator sees gaps and
     * can follow up; we deliberately do not silently hide missing info.
     */
    /*
     * Accommodation dispatch — Russian. Camp managers / homestay hosts
     * across Uzbekistan are typically more comfortable in Russian than
     * Uzbek for written booking confirmations. Sent per stay (one
     * message per InquiryStay row), so {stay_date} / {nights} /
     * {guest_count} reference THIS leg of the tour, not the whole
     * inquiry.
     */
    'accommodation_dispatch_ru' => <<<TXT
🏕 Размещение: {accommodation}
👤 Гость: {customer_name_with_country}
📅 Дата заселения: {stay_date}
🌙 Ночей: {nights}
👨‍👩‍👧 Количество гостей: {guest_count}
📞 Тел. гостя: {customer_phone}
🚗 Водитель: {driver_name} · {driver_phone}
🧭 Гид: {guide_name} · {guide_phone}
🍽 Питание: {meal_plan}
📝 Примечания: {notes}

🤝 Jahongir Travel — {reference}
TXT,

    'driver_dispatch_uz' => <<<TXT
🏕 Sayohat turi: {tour}
🗺 Yo'nalish: {direction} ({tour_type})
👤 Mehmon: {customer_name_with_country}
🕐 Vaqti: {pickup_time}
📅 Sana: {travel_date}
👥 Odam soni: {pax}
📱 Mehmon telefoni: {customer_phone}
🏨 Mehmonxona / olib ketish: {pickup_point}
🏁 Tushirish joyi: {dropoff_point}
🧭 Gid: {guide_name} · {guide_phone}

{notes}

🤝 Jahongir Travel — {reference}
TXT,

    'guide_dispatch_uz' => <<<TXT
🏕 Sayohat turi: {tour}
🗺 Yo'nalish: {direction} ({tour_type})
👤 Mehmon: {customer_name_with_country}
🕐 Vaqti: {pickup_time}
📅 Sana: {travel_date}
👥 Odam soni: {pax}
📱 Mehmon telefoni: {customer_phone}
🏨 Mehmonxona / olib ketish: {pickup_point}
🏁 Tushirish joyi: {dropoff_point}
🚗 Haydovchi: {driver_name} · {driver_phone}

{notes}

🤝 Jahongir Travel — {reference}
TXT,

    // Supplier (driver/guide) cancellation notice. Short + unmistakable.
    // Phase 17: sent when a GYG cancellation email cancels the inquiry.
    'supplier_cancellation_uz' => <<<TXT
❌ Tur bekor qilindi

📅 Sana: {travel_date}
👤 Mehmon: {customer_name}
🏕 Tur: {tour}
🕐 Vaqti: {pickup_time}
📋 Ref: {reference}
TXT,

];
