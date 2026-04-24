<?php

use App\Models\BookingInquiry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of regenerate-payment-link rollout — shadow mode only.
 *
 * This migration is behaviour-neutral: the callback still reads via
 * booking_inquiries.octo_transaction_id (unchanged). Phase 2 flips the
 * callback to read via this table.
 *
 * The table is append-only: once a row is created it is never deleted,
 * only status-transitioned (active → superseded | paid | failed). This
 * gives us immutable audit history for every Octo link ever generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('octo_payment_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inquiry_id')
                ->constrained('booking_inquiries')
                ->cascadeOnDelete()
                ->comment('Parent inquiry. If inquiry deleted, attempt history goes with it (attempts have no standalone meaning).');

            $table->string('transaction_id', 128)
                ->unique()
                ->comment('Octo shop_transaction_id (e.g. inquiry_10_abc123). Uniqueness authority — a txn id can only belong to one attempt, across all inquiries.');

            $table->decimal('amount_online_usd', 10, 2)
                ->comment('USD amount charged on THIS attempt. Frozen at attempt creation so a callback on a superseded link records the correct historical amount, not the current inquiry amount.');

            $table->decimal('price_quoted_at_attempt', 10, 2)->nullable()
                ->comment('Snapshot of inquiry.price_quoted when this attempt was created. Audit context for why this amount was charged.');

            $table->decimal('exchange_rate_used', 12, 4)->nullable()
                ->comment('USD→UZS rate at attempt creation. Nullable on backfilled rows (pre-attempt-system history — rate was not persisted per-link before this table existed).');

            $table->unsignedInteger('uzs_amount')->nullable()
                ->comment('UZS amount actually sent to Octo. Nullable on backfilled rows (unavailable for pre-attempt-system history).');

            // active     — current link for the inquiry (exactly one per inquiry when present)
            // superseded — replaced by a newer attempt via regenerate. Still payable on Octo side.
            // paid       — callback success received for this attempt
            // failed     — callback failure received (operator can retry by regenerating)
            $table->string('status', 16)->default('active')
                ->comment('Lifecycle: active | superseded | paid | failed.');

            $table->timestamp('superseded_at')->nullable()
                ->comment('When status flipped to superseded (operator regenerated).');

            $table->timestamps();

            $table->index(['inquiry_id', 'status']);
            $table->index('status');
        });

        // Backfill: one attempt row per inquiry that already has a link.
        // status=paid for already-paid rows, status=active otherwise.
        // uzs_amount + exchange_rate_used stay NULL — historical data is
        // unrecoverable (the Octo link was generated before this table
        // existed). This is documented on the column comments.
        //
        // amount_online_usd falls back to price_quoted for rows where
        // split-payment backfill didn't run (cancelled/spam at that time)
        // but still has a link. Guarantees a non-null attempt row.
        $rows = DB::table('booking_inquiries')
            ->whereNotNull('payment_link')
            ->whereNotNull('octo_transaction_id')
            ->whereNotIn('status', ['cancelled', 'spam'])
            ->select(
                'id',
                'octo_transaction_id',
                'amount_online_usd',
                'price_quoted',
                'paid_at',
                'payment_link_sent_at',
                'created_at',
            )
            ->get();

        $now = now();
        $seeds = [];
        foreach ($rows as $row) {
            $amount = $row->amount_online_usd ?? $row->price_quoted;
            if ($amount === null) {
                // Nothing meaningful to record — skip defensively.
                continue;
            }

            $seeds[] = [
                'inquiry_id'              => $row->id,
                'transaction_id'          => $row->octo_transaction_id,
                'amount_online_usd'       => $amount,
                'price_quoted_at_attempt' => $row->price_quoted,
                'exchange_rate_used'      => null,
                'uzs_amount'              => null,
                'status'                  => $row->paid_at ? 'paid' : 'active',
                'superseded_at'           => null,
                'created_at'              => $row->payment_link_sent_at ?? $row->created_at ?? $now,
                'updated_at'              => $now,
            ];
        }

        if ($seeds !== []) {
            // Chunked to keep INSERT size sane if the row count ever grows.
            foreach (array_chunk($seeds, 200) as $chunk) {
                DB::table('octo_payment_attempts')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('octo_payment_attempts');
    }
};
