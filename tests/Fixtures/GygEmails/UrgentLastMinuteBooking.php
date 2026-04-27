<?php

declare(strict_types=1);

/**
 * Anti-drift fixture: GYG "Urgent: New booking received" / last-minute variant.
 *
 * Captured from production incident 2026-04-27 (GYG48YVRXWBH, Wang Ting).
 * Combines two real-world axes the original parser missed:
 *   1. Subject prefix "Urgent: New booking received"
 *   2. Body trigger "received a last-minute booking:" (vs "has been booked:")
 *   3. Multi-word language "Traditional Chinese"
 *
 * If this test ever fails, GYG has changed their copy again and the
 * pipeline will start dropping urgent bookings on the floor — investigate
 * before merging.
 */

return [
    'name'    => 'urgent_last_minute_booking',
    'subject' => 'Urgent: New booking received - S374926 - GYG48YVRXWBH',
    'from'    => 'do-not-reply@notification.getyourguide.com',
    'body'    => <<<'BODY'
Hi Supply Partner, great news!
You've received a last-minute booking:

Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour

Samarkand to Bukhara: 2-Day Group Yurt & Camel

Reference numbergyg48yvrxwbh

DateApril 28, 2026 8:30 AM

Number of participants1 x Adult (Age 6 - 99)

Main customerWANG TING customer-eo44ny4uby3r5nou@reply.getyourguide.com
Phone: +886987293901
Language: Traditional Chinese

Price$ 220.00open booking
BODY,
    'expected' => [
        'classification'        => 'new_booking',
        'gyg_booking_reference' => 'GYG48YVRXWBH',
        'tour_name'             => 'Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour',
        'option_title'          => 'Samarkand to Bukhara: 2-Day Group Yurt & Camel',
        'guest_name'            => 'WANG TING',
        'guest_phone'           => '+886987293901',
        'travel_date'           => '2026-04-28',
        'travel_time'           => '08:30:00',
        'pax'                   => 1,
        'price'                 => 220.00,
        'currency'              => 'USD',
        'language'              => 'Traditional Chinese',
        'tour_type'             => 'group',
        'tour_type_source'      => 'explicit',
    ],
];
