<?php

declare(strict_types=1);

/**
 * Anti-drift fixture: standard "has been booked:" GYG booking email.
 *
 * Used by GygPipelineDriftTest to assert classifier acceptance and parser
 * completeness stay in sync. Any new accepted GYG booking subject pattern
 * MUST add/update a fixture in this directory.
 */

return [
    'name'    => 'standard_booking',
    'subject' => 'Booking - S374926 - GYGZGZ5XLFNQ',
    'from'    => 'do-not-reply@notification.getyourguide.com',
    'body'    => <<<'BODY'
Hi Supply Partner, great news!
Your offer has been booked:

Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour

Samarkand to Bukhara: 2-Day Group Yurt & Camel

Reference numbergygzgz5xlfnq

DateApril 19, 2026 9:00 AM

Number of participants2 x Adults (Age 0 - 99)

Main customerKatrine Arps Studskjær customer-fnygpmmlvad4gooy@reply.getyourguide.com
Phone: +4527890741
Language: Danish

Price$ 330.00open booking
BODY,
    'expected' => [
        'classification'        => 'new_booking',
        'gyg_booking_reference' => 'GYGZGZ5XLFNQ',
        'tour_name'             => 'Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour',
        'option_title'          => 'Samarkand to Bukhara: 2-Day Group Yurt & Camel',
        'guest_name'            => 'Katrine Arps Studskjær',
        'guest_phone'           => '+4527890741',
        'travel_date'           => '2026-04-19',
        'travel_time'           => '09:00:00',
        'pax'                   => 2,
        'price'                 => 330.00,
        'currency'              => 'USD',
        'language'              => 'Danish',
        'tour_type'             => 'group',
        'tour_type_source'      => 'explicit',
    ],
];
