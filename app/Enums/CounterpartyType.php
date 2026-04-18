<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Polymorphic counterparty typing for ledger_entries.
 *
 * counterparty_id is nullable: external parties (e.g. Octo gateway)
 * may not have an internal row to reference.
 */
enum CounterpartyType: string
{
    case Guest    = 'guest';
    case Supplier = 'supplier';   // accommodation / driver / guide / vendor
    case Agent    = 'agent';
    case Driver   = 'driver';
    case Guide    = 'guide';
    case Bank     = 'bank';
    case Internal = 'internal';   // drawer movement, shift handover, etc.
    case External = 'external';   // payment gateway, OTA
}
