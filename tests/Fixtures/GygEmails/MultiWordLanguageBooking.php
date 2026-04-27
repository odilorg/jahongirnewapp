<?php

declare(strict_types=1);

/**
 * Anti-drift fixture: standard subject + multi-word language.
 *
 * Pre-2026-04-27 the language regex was `Language:\s*(\w+)`, which silently
 * truncated "Brazilian Portuguese" → "Brazilian", "Simplified Chinese" →
 * "Simplified". Matters for guide assignment.
 *
 * This fixture isolates the language axis: same shape as StandardBooking,
 * different language string, so a regression to \w+ fails here visibly
 * even if the urgent-variant fixture happens to pass.
 */

return [
    'name'    => 'multi_word_language_booking',
    'subject' => 'Booking - S374926 - GYGBRPT12345',
    'from'    => 'do-not-reply@notification.getyourguide.com',
    'body'    => <<<'BODY'
Hi Supply Partner, great news!
Your offer has been booked:

Samarkand: City Walking Tour

Samarkand: Half-Day Group Walking Tour with Local Guide

Reference numbergygbrpt12345

DateMay 5, 2026 10:00 AM

Number of participants3 x Adults (Age 0 - 99)

Main customerJoão Pedro da Silva customer-brpt99999@reply.getyourguide.com
Phone: +5511999988877
Language: Brazilian Portuguese

Price$ 150.00open booking
BODY,
    'expected' => [
        'classification'        => 'new_booking',
        'gyg_booking_reference' => 'GYGBRPT12345',
        'tour_name'             => 'Samarkand: City Walking Tour',
        'option_title'          => 'Samarkand: Half-Day Group Walking Tour with Local Guide',
        'guest_name'            => 'João Pedro da Silva',
        'guest_phone'           => '+5511999988877',
        'travel_date'           => '2026-05-05',
        'travel_time'           => '10:00:00',
        'pax'                   => 3,
        'price'                 => 150.00,
        'currency'              => 'USD',
        'language'              => 'Brazilian Portuguese',
        'tour_type'             => 'group',
        'tour_type_source'      => 'explicit',
    ],
];
