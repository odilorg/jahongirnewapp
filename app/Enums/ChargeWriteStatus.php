<?php

declare(strict_types=1);

namespace App\Enums;

enum ChargeWriteStatus: string
{
    /** No finance was provided — nothing to write. */
    case None = 'none';

    /** Charge item written to Beds24 successfully. */
    case Written = 'written';

    /**
     * Finance was provided but write_charge_items config is disabled.
     * Shown to operator so they know the amount was NOT written.
     */
    case SkippedFeatureDisabled = 'skipped_feature_disabled';

    /**
     * Multiple rooms were booked with a combined total.
     * Automatic charge split is not supported in v1 — operator must add manually.
     */
    case SkippedMultiRoomUnsupported = 'skipped_multi_room_total_unsupported';

    /** Charge write was attempted but the Beds24 API call failed. */
    case Failed = 'failed';
}
