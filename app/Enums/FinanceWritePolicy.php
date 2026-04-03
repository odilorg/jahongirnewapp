<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceWritePolicy: string
{
    /**
     * Write a charge as invoiceItems type=1 only.
     * Default and only safe option until Phase 0 confirms top-level price behavior.
     */
    case InvoiceItemsOnly = 'invoice_items_only';

    /**
     * Write charge as invoiceItems type=1 AND mirror to the top-level price field.
     * Only enable after Phase 0 Experiment C confirms no double-counting.
     */
    case InvoiceItemsPlusPriceMirror = 'invoice_items_plus_price_mirror';

    /**
     * Resolve from config. Falls back to InvoiceItemsOnly on any unrecognised value
     * — fail-safe toward the simpler, lower-risk write path.
     */
    public static function fromConfig(): self
    {
        $value = config('services.booking_bot.finance_write_policy', self::InvoiceItemsOnly->value);

        return self::tryFrom((string) $value) ?? self::InvoiceItemsOnly;
    }
}
