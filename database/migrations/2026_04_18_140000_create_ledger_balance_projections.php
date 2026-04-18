<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L-005 — balance projections derived from ledger_entries.
 *
 * Two projections shipped here:
 *
 *  - cash_drawer_balances :  (cash_drawer_id, currency) -> running balance
 *  - shift_balances       :  (cashier_shift_id, currency) -> running balance
 *
 * These are DERIVED, REBUILDABLE tables. The ledger is the source of
 * truth; the listener (App\Listeners\Ledger\UpdateBalanceProjections)
 * maintains these incrementally, and the `ledger:rebuild-projections`
 * command can recompute them from scratch at any time.
 *
 * Both tables store last_entry_id + last_entry_at so operators can
 * trace "which ledger row last moved this balance" during audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_drawer_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cash_drawer_id')
                ->constrained('cash_drawers')
                ->cascadeOnDelete();

            $table->char('currency', 3);

            $table->decimal('balance',   14, 2)->default(0);
            $table->decimal('total_in',  14, 2)->default(0);
            $table->decimal('total_out', 14, 2)->default(0);
            $table->unsignedInteger('in_count')->default(0);
            $table->unsignedInteger('out_count')->default(0);

            $table->foreignId('last_entry_id')
                ->nullable()
                ->constrained('ledger_entries')
                ->nullOnDelete();
            $table->timestamp('last_entry_at')->nullable();

            $table->timestamp('updated_at')->nullable();

            $table->unique(['cash_drawer_id', 'currency'], 'cash_drawer_balances_drawer_currency_unique');
        });

        Schema::create('shift_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cashier_shift_id')
                ->constrained('cashier_shifts')
                ->cascadeOnDelete();

            $table->char('currency', 3);

            $table->decimal('balance',   14, 2)->default(0);
            $table->decimal('total_in',  14, 2)->default(0);
            $table->decimal('total_out', 14, 2)->default(0);
            $table->unsignedInteger('in_count')->default(0);
            $table->unsignedInteger('out_count')->default(0);

            $table->foreignId('last_entry_id')
                ->nullable()
                ->constrained('ledger_entries')
                ->nullOnDelete();
            $table->timestamp('last_entry_at')->nullable();

            $table->timestamp('updated_at')->nullable();

            $table->unique(['cashier_shift_id', 'currency'], 'shift_balances_shift_currency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_balances');
        Schema::dropIfExists('cash_drawer_balances');
    }
};
