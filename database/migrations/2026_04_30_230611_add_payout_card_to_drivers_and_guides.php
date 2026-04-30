<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier payout cards for P2P transfers.
 *
 * Operator pays drivers/guides directly to their bank cards after a tour.
 * Storing card_number (digits only, 16 chars), bank label, and holder
 * name speeds up the post-tour payout flow — single source of truth,
 * no more "wait what was your Humo number" SMS chains.
 *
 * Single card per supplier for v1. If suppliers start juggling multiple
 * cards, promote to a `supplier_cards` table later.
 *
 * Not PCI data — these are P2P recipient identifiers (no CVV, no PAN
 * authorization), so no encryption-at-rest requirement. Still treated
 * as PII: hidden from list tables, only visible on detail/edit/slideover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('card_number', 16)->nullable()->after('phone02');
            $table->string('card_bank', 32)->nullable()->after('card_number');
            $table->string('card_holder_name', 100)->nullable()->after('card_bank');
            $table->timestamp('card_updated_at')->nullable()->after('card_holder_name');
        });

        Schema::table('guides', function (Blueprint $table) {
            $table->string('card_number', 16)->nullable()->after('phone02');
            $table->string('card_bank', 32)->nullable()->after('card_number');
            $table->string('card_holder_name', 100)->nullable()->after('card_bank');
            $table->timestamp('card_updated_at')->nullable()->after('card_holder_name');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['card_number', 'card_bank', 'card_holder_name', 'card_updated_at']);
        });

        Schema::table('guides', function (Blueprint $table) {
            $table->dropColumn(['card_number', 'card_bank', 'card_holder_name', 'card_updated_at']);
        });
    }
};
