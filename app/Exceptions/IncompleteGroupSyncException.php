<?php

namespace App\Exceptions;

/**
 * Thrown by GroupAwareCashierAmountResolver when a group booking cannot be
 * fully resolved — expected group size exceeds locally-synced siblings AND
 * the on-demand Beds24 API fetch for missing siblings also failed.
 *
 * The cashier bot should catch this and display a safe, actionable message:
 * e.g. "This is a multi-room group booking. Not all rooms are synced yet.
 *       Please wait a moment and try again, or contact management."
 */
class IncompleteGroupSyncException extends \RuntimeException {}
