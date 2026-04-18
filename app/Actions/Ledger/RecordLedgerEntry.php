<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\DTOs\Ledger\LedgerEntryInput;
use App\Events\Ledger\LedgerEntryRecorded;
use App\Exceptions\Ledger\InvalidLedgerEntryException;
use App\Exceptions\Ledger\LedgerIdempotencyConflictException;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * THE single write contract for ledger_entries.
 *
 * Every money event — bot payment, webhook ingestion, Filament
 * adjustment, scheduled reconciliation — must go through this action.
 * Direct calls to LedgerEntry::create() are blocked by the runtime
 * write-firewall (L-018) and the CI guard (L-017).
 *
 * Responsibilities
 * ----------------
 *  1. Validate the DTO (synchronous local rules)
 *  2. DB-touching validations:
 *        - shift-open check when cashierShiftId set
 *        - reversal target exists in same currency
 *        - parent leg exists in same currency with opposite direction
 *  3. Idempotent replay: return the existing row for a known
 *     (source, idempotency_key). Throw on conflicting payload.
 *  4. Derive direction + trust_level + occurred_at
 *  5. Insert the row inside DB::transaction
 *  6. Emit LedgerEntryRecorded (consumed by projection listeners in L-005)
 */
final class RecordLedgerEntry
{
    public function execute(LedgerEntryInput $input): LedgerEntry
    {
        // 1. Local validation — no DB access.
        $input->validate();

        // 2. Idempotency fast-path — checked OUTSIDE the transaction so
        //    retried webhooks return the existing row without acquiring
        //    a write lock.
        if ($input->idempotencyKey !== null) {
            $existing = LedgerEntry::query()
                ->where('source', $input->source->value)
                ->where('idempotency_key', $input->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $this->handleIdempotentReplay($existing, $input);
            }
        }

        // 3. Resolve derived fields before opening the transaction.
        $direction  = $input->resolvedDirection();
        $occurredAt = $input->resolvedOccurredAt();
        $trustLevel = $input->source->trustLevel();

        // 4. Write inside DB::transaction. If the event listener throws
        //    the row is rolled back — callers see the exception, there
        //    is no partial state.
        return DB::transaction(function () use ($input, $direction, $occurredAt, $trustLevel) {
            // DB-touching validations happen inside the transaction so
            // they read the latest committed state.
            $this->validateAgainstDatabase($input, $direction);

            $entry = LedgerEntry::create([
                'ulid'                   => (string) Str::ulid(),
                'idempotency_key'        => $input->idempotencyKey,
                'occurred_at'            => $occurredAt,
                'recorded_at'            => now(),
                'entry_type'             => $input->entryType->value,
                'source'                 => $input->source->value,
                'trust_level'            => $trustLevel->value,
                'direction'              => $direction->value,
                'amount'                 => $input->amount,
                'currency'               => $input->currency->value,
                'fx_rate'                => $input->fxRate,
                'fx_rate_date'           => $input->fxRateDate,
                'daily_exchange_rate_id' => $input->dailyExchangeRateId,
                'exchange_rate_id'       => $input->exchangeRateId,
                'presentation_snapshot'  => $input->presentationSnapshot,
                'usd_equivalent'         => $input->usdEquivalent,
                'counterparty_type'      => $input->counterpartyType->value,
                'counterparty_id'        => $input->counterpartyId,
                'booking_inquiry_id'     => $input->bookingInquiryId,
                'beds24_booking_id'      => $input->beds24BookingId,
                'cashier_shift_id'       => $input->cashierShiftId,
                'cash_drawer_id'         => $input->cashDrawerId,
                'payment_method'         => $input->paymentMethod->value,
                'override_tier'          => $input->overrideTier->value,
                'override_approval_id'   => $input->overrideApprovalId,
                'variance_pct'           => $input->variancePct,
                'parent_entry_id'        => $input->parentEntryId,
                'reverses_entry_id'      => $input->reversesEntryId,
                'external_reference'     => $input->externalReference,
                'external_item_ref'      => $input->externalItemRef,
                'created_by_user_id'     => $input->createdByUserId,
                'created_by_bot_slug'    => $input->createdByBotSlug,
                'notes'                  => $input->notes,
                'tags'                   => $input->tags,
                'data_quality'           => $input->dataQuality->value,
                'created_at'             => now(),
            ]);

            // Structured log for observability — every successful write leaves a trace.
            Log::info('ledger.entry.recorded', [
                'id'               => $entry->id,
                'ulid'             => $entry->ulid,
                'source'           => $entry->source?->value,
                'entry_type'       => $entry->entry_type?->value,
                'direction'        => $entry->direction?->value,
                'amount'           => (string) $entry->amount,
                'currency'         => $entry->currency,
                'idempotency_key'  => $entry->idempotency_key,
                'beds24_booking_id'=> $entry->beds24_booking_id,
                'booking_inquiry_id' => $entry->booking_inquiry_id,
            ]);

            LedgerEntryRecorded::dispatch($entry);

            return $entry;
        });
    }

    // -------------------------------------------------------------------------

    /**
     * An idempotent replay arrived — either silently return the existing
     * row (identical payload) or throw a conflict (payload differs).
     */
    private function handleIdempotentReplay(LedgerEntry $existing, LedgerEntryInput $input): LedgerEntry
    {
        $stored = $this->storedFingerprint($existing);
        $incoming = $input->idempotencyFingerprint();

        $differences = [];
        foreach ($incoming as $key => $value) {
            if (($stored[$key] ?? null) !== $value) {
                $differences[$key] = [
                    'stored'   => $stored[$key] ?? null,
                    'incoming' => $value,
                ];
            }
        }

        if ($differences === []) {
            Log::info('ledger.entry.idempotent_replay', [
                'id'              => $existing->id,
                'source'          => $existing->source?->value,
                'idempotency_key' => $existing->idempotency_key,
            ]);
            return $existing;
        }

        // L-004 follow-up: idempotency conflicts are almost always a caller bug
        // (modified webhook payload, amount drift, stale retry). Surface loudly
        // so integration issues don't hide as silent exceptions.
        Log::warning('ledger.idempotency_conflict', [
            'existing_id'     => $existing->id,
            'source'          => $existing->source?->value,
            'idempotency_key' => $existing->idempotency_key,
            'differences'     => $differences,
        ]);

        throw new LedgerIdempotencyConflictException($existing, $differences);
    }

    private function storedFingerprint(LedgerEntry $entry): array
    {
        return [
            'entry_type'         => $entry->entry_type?->value,
            'direction'          => $entry->direction?->value,
            'amount'             => number_format((float) $entry->amount, 2, '.', ''),
            'currency'           => $entry->currency,
            'counterparty_type'  => $entry->counterparty_type?->value,
            'counterparty_id'    => $entry->counterparty_id,
            'payment_method'     => $entry->payment_method?->value,
            'external_reference' => $entry->external_reference,
            'external_item_ref'  => $entry->external_item_ref,
        ];
    }

    /**
     * DB-touching validation — runs inside the transaction so it
     * reads the latest committed state.
     */
    private function validateAgainstDatabase(LedgerEntryInput $input, $direction): void
    {
        // 1. Reversal target must exist (FK handles existence, but we
        //    want a clean exception and a currency check).
        if ($input->reversesEntryId !== null) {
            $original = LedgerEntry::find($input->reversesEntryId);
            if ($original === null) {
                throw new InvalidLedgerEntryException(
                    "reverses_entry_id={$input->reversesEntryId} does not exist."
                );
            }
            if ($original->currency !== $input->currency->value) {
                throw new InvalidLedgerEntryException(
                    "Reversal currency ({$input->currency->value}) does not match original ({$original->currency})."
                );
            }
            if ($original->reverses_entry_id !== null) {
                throw new InvalidLedgerEntryException(
                    "Cannot reverse entry #{$original->id} — it is itself a reversal."
                );
            }
        }

        // 2. Parent leg (exchange pair) must exist in same currency with
        //    opposite direction. A USD→UZS exchange writes leg A (out USD)
        //    then leg B (in UZS); leg B carries parent_entry_id=A.id.
        //    Invariant: the legs are always in DIFFERENT currencies but
        //    opposite directions, so the "same currency" check above is
        //    WRONG for parent_entry_id. We check direction opposite only.
        if ($input->parentEntryId !== null) {
            $parent = LedgerEntry::find($input->parentEntryId);
            if ($parent === null) {
                throw new InvalidLedgerEntryException(
                    "parent_entry_id={$input->parentEntryId} does not exist."
                );
            }
            if ($parent->direction === $direction) {
                throw new InvalidLedgerEntryException(
                    'Exchange legs must have opposite directions; '
                    . "parent is {$parent->direction->value}, incoming is {$direction->value}."
                );
            }
        }

        // 3. Shift-open check — if a cashier shift is specified, it must
        //    be open. Retrieving the shift here avoids an expensive join
        //    in downstream queries.
        if ($input->cashierShiftId !== null) {
            $isOpen = DB::table('cashier_shifts')
                ->where('id', $input->cashierShiftId)
                ->where('status', 'open')
                ->exists();
            if (! $isOpen) {
                throw new InvalidLedgerEntryException(
                    "Cashier shift #{$input->cashierShiftId} is not open; cannot record entries against it."
                );
            }
        }
    }
}
