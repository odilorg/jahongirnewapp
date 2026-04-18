<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L-003 — canonical append-only ledger for all money events.
 *
 * Every money event in the system will produce exactly one row here.
 * This migration is 100% additive: no existing table is altered,
 * no existing flow writes here yet. Production continues to use
 * cash_transactions / guest_payments / supplier_payments / cash_expenses
 * unchanged. Wiring happens in L-004 onward.
 *
 * Design notes
 * ------------
 *  - Rows are immutable by convention; the model enforces this with
 *    booted updating/deleting hooks.
 *  - Reversals are NEW rows with reverses_entry_id set, not in-place
 *    modifications. No reversed_by_entry_id column — derive on read.
 *  - Idempotency: UNIQUE(source, idempotency_key). Nullable key is
 *    allowed (MySQL treats multiple NULL values as distinct), so
 *    flows that do not supply a key can still insert.
 *  - Exchange pairs share parent_entry_id on the second leg.
 *  - Both daily_exchange_rate_id and exchange_rate_id are kept so that
 *    a backfill from cash_transactions can preserve whichever rate
 *    store the historical row referenced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            // --- Identity & idempotency -----------------------------------
            $table->id();
            $table->char('ulid', 26)->unique()
                ->comment('App-generated ULID for ordering + external reference');
            $table->string('idempotency_key', 191)->nullable()
                ->comment('Source-provided key — e.g. Octo transaction_id, Beds24 item id');

            // --- Temporal -------------------------------------------------
            $table->timestamp('occurred_at')
                ->comment('When the event happened in business time');
            $table->timestamp('recorded_at')
                ->comment('When the row was written');

            // --- Taxonomy (stored as enum codes) --------------------------
            $table->string('entry_type', 32)
                ->comment('See App\Enums\LedgerEntryType');
            $table->string('source', 32)
                ->comment('See App\Enums\SourceTrigger');
            $table->enum('trust_level', ['authoritative', 'operator', 'manual'])
                ->comment('Derived from source; stored for fast filtering');

            // --- Value ----------------------------------------------------
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 14, 2)
                ->comment('Always positive — direction determines sign in computation');
            $table->char('currency', 3);

            // --- FX snapshot (optional, frozen at entry time) -------------
            $table->decimal('fx_rate', 15, 4)->nullable()
                ->comment('Rate applied at write time (currency → base)');
            $table->date('fx_rate_date')->nullable();
            $table->foreignId('daily_exchange_rate_id')
                ->nullable()
                ->constrained('daily_exchange_rates')
                ->nullOnDelete();
            $table->foreignId('exchange_rate_id')
                ->nullable()
                ->constrained('exchange_rates')
                ->nullOnDelete();
            $table->json('presentation_snapshot')->nullable()
                ->comment('Frozen {uzs, eur, rub, usd, selected_currency} at presentation time');
            $table->decimal('usd_equivalent', 14, 2)->nullable();

            // --- Counterparty (polymorphic, typed) ------------------------
            $table->string('counterparty_type', 20)
                ->comment('See App\Enums\CounterpartyType');
            $table->unsignedBigInteger('counterparty_id')->nullable()
                ->comment('Nullable — external parties may have no internal row');

            // --- Domain context (nullable; each entry may pertain to 0-N) -
            $table->foreignId('booking_inquiry_id')
                ->nullable()
                ->constrained('booking_inquiries')
                ->nullOnDelete();
            $table->string('beds24_booking_id', 64)->nullable();
            $table->foreignId('cashier_shift_id')
                ->nullable()
                ->constrained('cashier_shifts')
                ->nullOnDelete();
            $table->foreignId('cash_drawer_id')
                ->nullable()
                ->constrained('cash_drawers')
                ->nullOnDelete();

            // --- Payment instrument ---------------------------------------
            $table->string('payment_method', 32)
                ->comment('See App\Enums\PaymentMethod');

            // --- Override / approval chain --------------------------------
            $table->enum('override_tier', ['none', 'cashier', 'manager', 'blocked'])
                ->default('none');
            $table->foreignId('override_approval_id')
                ->nullable()
                ->constrained('fx_manager_approvals')
                ->nullOnDelete();
            $table->decimal('variance_pct', 6, 2)->nullable();

            // --- Linkage (self-references; FKs added after create) --------
            $table->unsignedBigInteger('parent_entry_id')->nullable()
                ->comment('Leg-2 points to leg-1 of an exchange pair');
            $table->unsignedBigInteger('reverses_entry_id')->nullable()
                ->comment('This entry reverses the one it points at');

            // --- External reference (for dedup + backfill traceability) ---
            $table->string('external_reference', 191)->nullable()
                ->comment('Beds24 booking id, Octo tx id, GYG external_reference, etc.');
            $table->string('external_item_ref', 191)->nullable()
                ->comment('e.g. Beds24 payment item id for granular dedup');

            // --- Authorship -----------------------------------------------
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('created_by_bot_slug', 32)->nullable();

            // --- Audit ----------------------------------------------------
            $table->text('notes')->nullable();
            $table->json('tags')->nullable()
                ->comment('Free-form tags, e.g. ["group-pay","refund"]');
            $table->enum('data_quality', ['ok', 'backfilled', 'manual_review'])
                ->default('ok');

            // --- Timestamps -----------------------------------------------
            // created_at only — rows do not update; updated_at is absent.
            $table->timestamp('created_at')->nullable();

            // --- Indexes --------------------------------------------------
            $table->index('occurred_at');
            $table->index(['entry_type', 'occurred_at']);
            $table->index(['source', 'occurred_at']);
            $table->index(['counterparty_type', 'counterparty_id']);
            $table->index('beds24_booking_id');
            $table->index('external_reference');
            $table->index('parent_entry_id');
            $table->index('reverses_entry_id');

            // --- Idempotency ---------------------------------------------
            // Per (source, idempotency_key) — MySQL allows multiple NULL keys.
            $table->unique(['source', 'idempotency_key'], 'ledger_entries_source_idempotency_unique');
        });

        // Self-referencing FKs — added after the table exists so there's
        // no chicken-and-egg at CREATE time.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign('parent_entry_id', 'ledger_entries_parent_entry_fk')
                ->references('id')->on('ledger_entries')
                ->nullOnDelete();
            $table->foreign('reverses_entry_id', 'ledger_entries_reverses_entry_fk')
                ->references('id')->on('ledger_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign('ledger_entries_parent_entry_fk');
            $table->dropForeign('ledger_entries_reverses_entry_fk');
        });
        Schema::dropIfExists('ledger_entries');
    }
};
