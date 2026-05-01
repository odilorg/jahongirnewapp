<?php

declare(strict_types=1);

/**
 * Diagnostic chips revealed when a guest rates a role ≤ 3 stars.
 *
 * Keys are stable identifiers persisted in the JSON columns on
 * tour_feedbacks; labels are display-only and shown to the guest.
 *
 * Renaming a label is safe (no DB change). Adding a new key is safe.
 * REMOVING a key would orphan historic rows — prefer to leave a key
 * in place, just hide it from new forms via a separate "active" flag
 * if we ever need that.
 *
 * Multi-select; the guest taps as many as apply.
 */
return [
    'driver' => [
        'communication' => 'Communication',
        'driving'       => 'Driving / safety',
        'punctuality'   => 'Punctuality',
        'vehicle'       => 'Vehicle / cleanliness',
        'other'         => 'Other',
    ],

    'guide' => [
        'knowledge'    => 'Knowledge',
        'language'     => 'English / language',
        'friendliness' => 'Friendliness',
        'punctuality'  => 'Time management',
        'other'        => 'Other',
    ],

    'accommodation' => [
        'cleanliness' => 'Cleanliness',
        'food'        => 'Food',
        'comfort'     => 'Comfort',
        'service'     => 'Service',
        'other'       => 'Other',
    ],
];
