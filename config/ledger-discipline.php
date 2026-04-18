<?php

declare(strict_types=1);

/**
 * L-017 — Ledger discipline rules for the `ledger:guard` command.
 *
 * A rule describes an unwanted code pattern and where the pattern is
 * permitted. The scanner finds violations by matching `pattern`
 * against every PHP file under the scan roots, then filtering out
 * matches in `allowed_path_prefixes` or `baseline_files`.
 *
 * Severity levels
 * ---------------
 *  - 'strict'   violations fail CI (exit 1)
 *  - 'warn'     violations print a warning but do not fail CI
 *
 * Mode override
 * -------------
 *  The command accepts --strict (promote all warn rules to strict) or
 *  --warn-only (demote all strict rules to warn). Default mode reads
 *  each rule's configured severity as-is.
 *
 * Baseline files
 * --------------
 *  Some rules (legacy CashTransaction writes) cannot be absolute yet —
 *  live code still writes there. For those rules we pin a baseline of
 *  currently-known caller files. Any NEW file introducing the pattern
 *  is flagged; known callers are allowed. When all callers migrate to
 *  the ledger, rules move from 'warn + baseline' to 'strict + empty
 *  baseline'.
 */
return [

    'scan_roots' => [
        'app/',
    ],

    'rules' => [

        // ──────────────────────────────────────────────────────────────
        // Ledger invariants — absolute
        // ──────────────────────────────────────────────────────────────
        [
            'id'          => 'R1',
            'severity'    => 'strict',
            'description' => 'LedgerEntry writes must go through the canonical action',
            'pattern'     => '/LedgerEntry::(create|insert|firstOrCreate|updateOrCreate|upsert)\s*\(/',
            'allowed_path_prefixes' => [
                'app/Actions/Ledger/',
                'app/Services/Ledger/',
            ],
            'remediation' => 'Call App\Actions\Ledger\RecordLedgerEntry instead.',
        ],

        [
            'id'          => 'R2',
            'severity'    => 'strict',
            'description' => 'LedgerEntry::update/delete/destroy is forbidden — ledger is append-only',
            'pattern'     => '/LedgerEntry::(update|delete|destroy|truncate)\s*\(/',
            'allowed_path_prefixes' => [],
            'remediation' => 'Record a reversal via RecordLedgerEntry with reverses_entry_id.',
        ],

        // ──────────────────────────────────────────────────────────────
        // Balance projection integrity
        // ──────────────────────────────────────────────────────────────
        [
            'id'          => 'R3',
            'severity'    => 'strict',
            'description' => 'CashDrawerBalance writes belong to the projection updater only',
            'pattern'     => '/CashDrawerBalance::(create|update|insert|firstOrCreate|updateOrCreate|upsert|delete|destroy|truncate)\s*\(/',
            'allowed_path_prefixes' => [
                'app/Services/Ledger/',
                'app/Listeners/Ledger/',
                'app/Console/Commands/LedgerRebuildProjections.php',
            ],
            'remediation' => 'Projections are derived — update via BalanceProjectionUpdater.',
        ],

        [
            'id'          => 'R4',
            'severity'    => 'strict',
            'description' => 'ShiftBalance writes belong to the projection updater only',
            'pattern'     => '/ShiftBalance::(create|update|insert|firstOrCreate|updateOrCreate|upsert|delete|destroy|truncate)\s*\(/',
            'allowed_path_prefixes' => [
                'app/Services/Ledger/',
                'app/Listeners/Ledger/',
                'app/Console/Commands/LedgerRebuildProjections.php',
            ],
            'remediation' => 'Projections are derived — update via BalanceProjectionUpdater.',
        ],

        // ──────────────────────────────────────────────────────────────
        // Legacy cash_transactions — warn on NEW callers
        // ──────────────────────────────────────────────────────────────
        //
        // Current production still writes to cash_transactions alongside
        // the shadow ledger (L-006). Blocking direct writes today would
        // break the legacy path. Instead: pin a baseline of known callers
        // as of L-017 and warn on any file introducing a new write.
        //
        // When Phase C completes and legacy writes are retired, flip
        // this rule to 'strict' with an empty baseline — any remaining
        // CashTransaction::create becomes a CI failure.
        [
            'id'          => 'R5',
            'severity'    => 'warn',
            'description' => 'New CashTransaction::create callers must go through a ledger action',
            'pattern'     => '/CashTransaction::(create|insert|firstOrCreate|updateOrCreate|upsert)\s*\(/',
            'allowed_path_prefixes' => [],
            'baseline_files' => [
                // Controllers that write legacy rows today (to be migrated in L-007/L-009):
                'app/Http/Controllers/Beds24WebhookController.php',
                'app/Http/Controllers/OwnerBotController.php',
                'app/Http/Controllers/CashierBotController.php',

                // Services that still write legacy rows:
                'app/Services/CashierExpenseService.php',
                'app/Services/CashierExchangeService.php',
                'app/Services/BotPaymentService.php',

                // Filament paths that will be migrated in L-010:
                'app/Filament/Resources/CashTransactionResource/Pages/CreateCashTransaction.php',

                // The single-use-case action (still legacy-era):
                'app/Actions/RecordTransactionAction.php',
            ],
            'remediation' => 'Add the adapter call pattern from Beds24WebhookController (L-006) — use RecordLedgerEntry via a source adapter.',
        ],

    ],
];
